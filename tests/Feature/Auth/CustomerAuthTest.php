<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contracts\SmsSender;
use App\Enums\CycleStatus;
use App\Models\Customer;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Sms\LogSmsSender;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer login: a phone number and a six-digit code.
 *
 * ⚠️ TWO OF THESE ARE THE MILESTONE.
 *
 *  - `test_a_customer_token_cannot_reach_a_staff_route` — brief Law 3, and the specific trap
 *    is that `createToken($name)` defaults to the `*` ability, which Sanctum's `can()`
 *    honours. A customer token minted carelessly would satisfy any `can:staff` check.
 *  - `test_order_history_finds_orders_placed_as_a_guest` — the decision that makes accounts
 *    worth having. History keys on the verified phone, not `customer_id`, so signing up does
 *    not appear to erase eleven previous orders.
 */
final class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    private CycleDay $day;

    private MenuOption $waakye;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));

        $cycle = app(CycleBuilder::class)->create([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-14T18:00:00Z',
        ]);
        $cycle->update(['status' => CycleStatus::Published->value]);

        $this->day = $cycle->days()->orderBy('date')->firstOrFail();

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $this->day->id, 'menu_item_id' => $item->id],
            ['is_available' => true],
        );
        $this->waakye = $item->options()->firstOrFail();

        $this->log()->flush();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function log(): LogSmsSender
    {
        $sender = app(SmsSender::class);

        // If this fails, the binding changed and the suite is one config away from texting
        // real people their login codes.
        $this->assertInstanceOf(LogSmsSender::class, $sender, 'Tests must run on the log driver.');

        return $sender;
    }

    /** The code, read out of the message that was "sent". The only way to get it. */
    private function codeSentTo(string $phone): string
    {
        $messages = $this->log()->sentTo($phone);
        $last = end($messages);

        $this->assertNotFalse($last, "No code was sent to {$phone}.");
        preg_match('/\b(\d{6})\b/', $last['message'], $matches);

        return $matches[1] ?? '';
    }

    private function requestCode(string $phone = '0244000111'): string
    {
        $this->postJson('/api/v1/customer/otp', ['phone' => $phone])->assertOk();

        return $this->codeSentTo('+233244000111');
    }

    /** Sign in and return the bearer token. */
    private function signIn(string $phone = '0244000111'): string
    {
        $code = $this->requestCode($phone);

        return $this->postJson('/api/v1/customer/otp/verify', ['phone' => $phone, 'code' => $code])
            ->assertOk()
            ->json('data.token');
    }

    private function guestOrder(string $phone): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->waakye->id, 2)],
            type: \App\Enums\OrderType::Pickup,
            source: \App\Enums\OrderSource::Online,
            contactName: 'Ama Serwaa',
            contactPhone: $phone,
            cycleDayId: $this->day->id,
        ));
    }

    // ── ⚠️ The wall between the two login paths ───────────────────────────────

    /**
     * ⚠️ BRIEF LAW 3. A customer token satisfies `auth:sanctum` perfectly well; it must not
     * satisfy the staff middleware.
     *
     * The trap this guards is `createToken($name)` defaulting to `['*']`, which Sanctum's
     * `can()` honours — so a carelessly minted customer token would pass any `can:staff`
     * check in the framework's own terms. `EnsureStaffAbility` does a membership check
     * instead, which is why this passes.
     */
    public function test_a_customer_token_cannot_reach_a_staff_route(): void
    {
        $token = $this->signIn();

        $this->withToken($token)->getJson('/api/v1/customer/me')->assertOk();
        $this->forgetAuth();

        $this->withToken($token)->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->forgetAuth();
        $this->withToken($token)->getJson('/api/v1/admin/insights')->assertForbidden();
    }

    /** And the token carries exactly one ability, not the wildcard. */
    public function test_a_customer_token_holds_only_the_customer_ability(): void
    {
        $this->signIn();

        $abilities = \Laravel\Sanctum\PersonalAccessToken::query()->latest('id')->firstOrFail()->abilities;

        $this->assertSame(['customer'], $abilities);
        $this->assertNotContains('*', $abilities);
    }

    /**
     * ⚠️ A STAFF MEMBER REACHING A CUSTOMER ROUTE GETS A 403, NOT A NEW CUSTOMER ROW.
     *
     * These routes are `auth:sanctum` only, so a staff token authenticates fine. Creating a
     * profile on the fly would give every staff member a shadow customer account keyed to
     * their work number.
     */
    public function test_a_staff_token_gets_no_customer_profile(): void
    {
        $staff = User::query()->where('email', 'mef@mefs.local')->firstOrFail();

        $this->withToken($staff->createToken('test', ['staff'])->plainTextToken)
            ->getJson('/api/v1/customer/me')
            ->assertForbidden();

        $this->assertSame(0, Customer::query()->count());
    }

    // ── ⚠️ Order history ──────────────────────────────────────────────────────

    /**
     * ⚠️ THE DECISION THAT MAKES ACCOUNTS WORTH HAVING.
     *
     * A guest orders with `customer_id: null`. Keying history on the foreign key would show
     * somebody who has ordered for months an empty list on the day they sign up, and the
     * obvious conclusion is that the account lost their history.
     */
    public function test_order_history_finds_orders_placed_as_a_guest(): void
    {
        $order = $this->guestOrder('0244000111');
        $this->assertNull($order->customer_id, 'This test needs a guest order.');

        $response = $this->withToken($this->signIn())
            ->getJson('/api/v1/customer/orders')
            ->assertOk();

        $this->assertCount(1, $response->json('data.orders'));
        $this->assertSame($order->order_number, $response->json('data.orders.0.order_number'));
    }

    /** Somebody else's orders are not yours, however they were placed. */
    public function test_order_history_is_only_your_own_number(): void
    {
        $this->guestOrder('0244000111');
        $this->guestOrder('0209999888');

        $response = $this->withToken($this->signIn('0244000111'))
            ->getJson('/api/v1/customer/orders')
            ->assertOk();

        $this->assertCount(1, $response->json('data.orders'));
    }

    /** Staff-only fields must not ride along on the customer's own history. */
    public function test_order_history_carries_no_staff_fields(): void
    {
        $order = $this->guestOrder('0244000111');
        $order->update(['internal_notes' => 'Always late, call ahead']);

        $body = $this->withToken($this->signIn())->getJson('/api/v1/customer/orders')->getContent();

        $this->assertStringNotContainsString('Always late', $body);
        $this->assertStringNotContainsString('internal_notes', $body);
    }

    // ── The code itself ───────────────────────────────────────────────────────

    /**
     * ⚠️ THE CODE IS NEVER IN A RESPONSE, IN ANY ENVIRONMENT.
     *
     * A convenience that returns live credentials in `local` is a convenience that ships.
     * Local development reads it from the log driver's output, which is where the message
     * actually went.
     */
    public function test_the_code_is_never_in_the_response(): void
    {
        $body = $this->postJson('/api/v1/customer/otp', ['phone' => '0244000111'])
            ->assertOk()
            ->getContent();

        $code = $this->codeSentTo('+233244000111');

        $this->assertSame(6, strlen($code));
        $this->assertStringNotContainsString($code, $body);
    }

    /** ⚠️ Stored hashed. This table is a list of live credentials. */
    public function test_the_code_is_hashed_at_rest(): void
    {
        $code = $this->requestCode();

        $stored = Otp::query()->latest('id')->firstOrFail()->code_hash;

        $this->assertNotSame($code, $stored);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($code, $stored));
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->requestCode();

        $this->postJson('/api/v1/customer/otp/verify', ['phone' => '0244000111', 'code' => '000000'])
            ->assertStatus(422);

        $this->assertSame(0, Customer::query()->count());
    }

    /**
     * ⚠️ FIVE ATTEMPTS, COUNTED ON THE ROW — not per IP, which an attacker rotates around.
     * The sixth try fails even with the right code, because the credential itself is spent.
     */
    public function test_a_code_burns_out_after_five_wrong_attempts(): void
    {
        $code = $this->requestCode();

        for ($i = 0; $i < Otp::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/customer/otp/verify', ['phone' => '0244000111', 'code' => '000000'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/customer/otp/verify', ['phone' => '0244000111', 'code' => $code])
            ->assertStatus(422);

        $this->assertSame(0, Customer::query()->count());
    }

    /** ⚠️ Two live codes for one number doubles the guessing surface. */
    public function test_requesting_a_new_code_kills_the_old_one(): void
    {
        $first = $this->requestCode();

        $this->travel(OtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $second = $this->requestCode();

        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/customer/otp/verify', ['phone' => '0244000111', 'code' => $first])
            ->assertStatus(422);

        $this->postJson('/api/v1/customer/otp/verify', ['phone' => '0244000111', 'code' => $second])
            ->assertOk();
    }

    /**
     * ⚠️ THE ROUTE'S LIMIT IS THE LIMIT THAT ACTUALLY APPLIES.
     *
     * This route sits inside a `throttle:60,1` group. An inline `throttle:12,1` on top of
     * that would NOT give twelve attempts a minute — `ThrottleRequests` keys on the request
     * signature and nothing about which middleware instance is asking, so both increment one
     * bucket and the tighter limit trips at six. The guard in the route file would be half
     * the guard it appears to be.
     *
     * Named limiters prefix their own name into the key. Ten verify attempts per number is
     * comfortably more than the five `Otp::MAX_ATTEMPTS` allows, so the code burning out is
     * what a caller hits first — which is the design, and it only holds while this passes.
     */
    public function test_the_attempt_counter_is_reached_before_the_route_throttle(): void
    {
        $this->requestCode();

        for ($i = 0; $i < Otp::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/customer/otp/verify', ['phone' => '0244000111', 'code' => '000000'])
                ->assertStatus(422, "Attempt {$i} was throttled before the counter ran out.");
        }

        $this->assertSame(Otp::MAX_ATTEMPTS, Otp::query()->latest('id')->firstOrFail()->attempts);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->requestCode();

        $this->travel(Otp::LIFETIME_MINUTES + 1)->minutes();

        $this->postJson('/api/v1/customer/otp/verify', ['phone' => '0244000111', 'code' => $code])
            ->assertStatus(422);
    }

    /**
     * ⚠️ 429 WITH THE WAIT, NOT A SILENT SUCCESS. A customer who did not get the text needs
     * to know when to press the button; pretending to resend leaves them tapping a button
     * that appears broken.
     */
    public function test_asking_again_too_soon_says_how_long_to_wait(): void
    {
        $this->postJson('/api/v1/customer/otp', ['phone' => '0244000111'])->assertOk();

        $response = $this->postJson('/api/v1/customer/otp', ['phone' => '0244000111'])
            ->assertStatus(429);

        $this->assertGreaterThan(0, $response->json('errors.retry_after.0'));
        $this->assertCount(1, $this->log()->sentTo('+233244000111'), 'A second text was sent.');
    }

    // ── The account ───────────────────────────────────────────────────────────

    /**
     * ⚠️ SIGNING IN ADOPTS AN EXISTING RECORD RATHER THAN MAKING A SECOND ONE. The phone is
     * unique, and a guest who has been ordering for months is already in this table.
     */
    public function test_signing_in_adopts_the_existing_customer_record(): void
    {
        $existing = Customer::query()->create(['name' => 'Ama Serwaa', 'phone' => '+233244000111']);

        $this->signIn();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($existing->id, Customer::query()->firstOrFail()->id);
        $this->assertNotNull($existing->refresh()->user_id, 'The account was never linked.');
    }

    /** No password is ever set on a customer account — there is nothing to leak or reuse. */
    public function test_a_customer_account_has_no_usable_password(): void
    {
        $this->signIn();

        $user = Customer::query()->firstOrFail()->user;

        $this->assertNotNull($user);
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('', $user->password));
        $this->assertNull($user->roleEnum(), 'A customer must hold no role.');
    }

    public function test_saved_details_are_defaults_and_never_rewrite_an_order(): void
    {
        $order = $this->guestOrder('0244000111');
        $token = $this->signIn();

        $this->withToken($token)
            ->patchJson('/api/v1/customer/me', ['default_address' => '14 Ring Road East'])
            ->assertOk()
            ->assertJsonPath('data.default_address', '14 Ring Road East');

        $this->assertNull($order->refresh()->delivery_address, 'An old order was rewritten.');
    }

    public function test_signing_out_kills_only_this_device(): void
    {
        $first = $this->signIn();
        $this->travel(OtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $second = $this->signIn();

        $this->withToken($first)->postJson('/api/v1/customer/logout')->assertOk();
        $this->forgetAuth();

        $this->withToken($first)->getJson('/api/v1/customer/me')->assertUnauthorized();
        $this->forgetAuth();
        $this->withToken($second)->getJson('/api/v1/customer/me')->assertOk();
    }
}
