<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use App\Contracts\SmsSender;
use App\Enums\CycleStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderStatusMachine;
use App\Services\Sms\LogSmsSender;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The waitlist, and the collection reminder.
 *
 * ⚠️ THE ONE THAT MATTERS IS `test_cancelling_an_order_texts_the_next_person_waiting`.
 * Everything else guards it: a waitlist that captures names and never notifies anybody is a
 * form that pretends to do something, which is worse than no form.
 */
final class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    private CycleDay $day;

    private MenuItem $waakye;

    private MenuOption $option;

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
        $this->waakye = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $this->day->id, 'menu_item_id' => $this->waakye->id],
            ['is_available' => true, 'portion_capacity' => 2],
        );

        $etor = MenuItem::query()->where('slug', 'plantain-etor')->firstOrFail();
        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $this->day->id, 'menu_item_id' => $etor->id],
            ['is_available' => true, 'portion_capacity' => 2],
        );

        /*
         * ⚠️ THE DAY IS CAPPED TOO, AND WITHOUT IT MOST OF THIS FILE CANNOT BE WRITTEN.
         *
         * You cannot join a waitlist for something that is not sold out — that refusal is a
         * feature. So a test needing an "anything that day" entry needs the DAY sold out,
         * not just one dish, because that is the gate a null `menu_item_id` is checked
         * against.
         *
         * ⚠️ AND IT IS **1**, NOT 2, BECAUSE `cycle_days.capacity` COUNTS ORDERS WHILE
         * `cycle_day_items.portion_capacity` COUNTS PORTIONS. Two columns called capacity in
         * different units, one row apart. `sellOut()` places a single order of two portions,
         * which fills a day capacity of 1 and a portion capacity of 2 at the same time.
         */
        $this->day->update(['capacity' => 1]);

        $this->option = $this->waakye->options()->firstOrFail();

        $this->log()->flush();
    }

    private function log(): LogSmsSender
    {
        $sender = app(SmsSender::class);
        $this->assertInstanceOf(LogSmsSender::class, $sender, 'Tests must run on the log driver.');

        return $sender;
    }

    private function staff(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    /** Fill the day's two portions so the gate reports `sold_out`. */
    private function sellOut(string $phone = '0244000111'): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->option->id, 2)],
            type: OrderType::Pickup,
            source: OrderSource::Online,
            contactName: 'Ama Serwaa',
            contactPhone: $phone,
            cycleDayId: $this->day->id,
        ));
    }

    private function join(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/waitlist', array_merge([
            'cycle_day_id' => $this->day->id,
            'menu_item_id' => $this->waakye->id,
            'name' => 'Kofi Mensah',
            'phone' => '0209999888',
            'quantity' => 1,
        ], $overrides));
    }

    // ── ⚠️ The one the feature exists for ─────────────────────────────────────

    /**
     * ⚠️ A CANCELLATION IS THE ONLY MOMENT CAPACITY COMES BACK ON ITS OWN.
     *
     * The notification has to run AFTER the transaction commits, or the notifier re-reads
     * the day and sees the portions still sold — and decides there is nothing to offer, on
     * the exact event the waitlist was built for.
     */
    public function test_cancelling_an_order_texts_the_next_person_waiting(): void
    {
        $order = $this->sellOut();
        $this->join()->assertCreated();
        $this->log()->flush();

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $messages = $this->log()->sentTo('+233209999888');

        $this->assertCount(1, $messages, 'Nobody on the waitlist was told.');
        $this->assertStringContainsString('Waakye', $messages[0]['message']);
        $this->assertStringContainsString('First come', $messages[0]['message']);

        $this->assertSame('notified', WaitlistEntry::query()->firstOrFail()->status);
    }

    /** Somebody waiting on waakye is not told the etor came back. */
    public function test_only_the_dish_that_freed_up_is_notified(): void
    {
        $etor = MenuItem::query()->where('slug', 'plantain-etor')->firstOrFail();

        // Sells out the day as well as the waakye, so both waiters may legitimately join.
        $order = $this->sellOut();
        $this->join(['menu_item_id' => $etor->id, 'phone' => '0209999777'])->assertCreated();
        $this->join(['menu_item_id' => $this->waakye->id, 'phone' => '0209999888'])->assertCreated();
        $this->log()->flush();

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $this->assertCount(1, $this->log()->sentTo('+233209999888'));
        $this->assertCount(0, $this->log()->sentTo('+233209999777'), 'The wrong dish was notified.');
    }

    /** A null `menu_item_id` means "anything that day", and is matched by any dish. */
    public function test_somebody_waiting_on_anything_is_told_whatever_comes_back(): void
    {
        $order = $this->sellOut();
        $this->join(['menu_item_id' => null, 'phone' => '0209999666'])->assertCreated();
        $this->log()->flush();

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $this->assertCount(1, $this->log()->sentTo('+233209999666'));
    }

    /**
     * ⚠️ FIRST IN, FIRST TEXTED — and only as many as the freed portions cover.
     *
     * Texting everybody would be one portion and four people racing for it, which produces
     * three complaints per sale.
     */
    public function test_only_as_many_are_told_as_there_are_portions(): void
    {
        // Two portions on the day, sold as one order of two.
        $order = $this->sellOut();

        $this->join(['phone' => '0209999111', 'quantity' => 2])->assertCreated();
        $this->join(['phone' => '0209999222', 'quantity' => 2])->assertCreated();
        $this->join(['phone' => '0209999333', 'quantity' => 2])->assertCreated();
        $this->log()->flush();

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $this->assertCount(1, $this->log()->sentTo('+233209999111'), 'The first in line was skipped.');
        $this->assertCount(0, $this->log()->sentTo('+233209999222'));
        $this->assertCount(0, $this->log()->sentTo('+233209999333'));

        // And the two who were not told are still waiting for the next release.
        $this->assertSame(2, WaitlistEntry::query()->where('status', 'waiting')->count());
    }

    /**
     * ⚠️ NOBODY IS TEXTED ABOUT A DAY THEY CANNOT ORDER FOR.
     *
     * A portion freeing up after the cutoff is a portion nobody can buy. Texting about it
     * produces a phone call rather than a sale.
     */
    public function test_nobody_is_told_once_the_day_is_past_its_cutoff(): void
    {
        $order = $this->sellOut();
        $this->join()->assertCreated();

        $this->day->cycle->update(['orders_close_at' => now()->subHour()]);
        $this->log()->flush();

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $this->assertCount(0, $this->log()->sentTo('+233209999888'));
        $this->assertSame('waiting', WaitlistEntry::query()->firstOrFail()->status);
    }

    // ── Joining ───────────────────────────────────────────────────────────────

    /**
     * ⚠️ REFUSED WHILE THE DAY IS STILL ORDERABLE, AND THE REFUSAL IS THE FEATURE. A waitlist
     * entry for food somebody could just buy is a customer waiting for a text about
     * something already on the shelf.
     */
    public function test_joining_is_refused_while_the_day_is_still_open(): void
    {
        $this->join()->assertStatus(422);

        $this->assertSame(0, WaitlistEntry::query()->count());
    }

    /** Past the cutoff is not something a freed portion fixes, so it is not a waitlist. */
    public function test_joining_is_refused_when_the_day_is_closed_rather_than_sold_out(): void
    {
        $this->day->cycle->update(['orders_close_at' => now()->subHour()]);

        $response = $this->join()->assertStatus(422);

        $this->assertSame('cutoff_passed', $response->json('errors.refusal.reason'));
        $this->assertSame(0, WaitlistEntry::query()->count());
    }

    /**
     * ⚠️ ONE ENTRY PER NUMBER PER DISH PER DAY, enforced by a unique index rather than only
     * in the controller — a retried request defeats a check-then-insert.
     */
    public function test_joining_twice_leaves_one_entry(): void
    {
        $this->sellOut();

        $this->join()->assertCreated();
        $this->join(['quantity' => 3])->assertCreated();

        $this->assertSame(1, WaitlistEntry::query()->count());
        $this->assertSame(3, WaitlistEntry::query()->firstOrFail()->quantity, 'The re-join did not update.');
    }

    /**
     * ⚠️ RE-JOINING MUST NOT RESURRECT A NOTIFIED ENTRY, or a second cancellation texts the
     * same person again about a portion they already heard about.
     */
    public function test_rejoining_does_not_undo_a_notification(): void
    {
        $order = $this->sellOut();
        $this->join()->assertCreated();

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());
        $this->assertSame('notified', WaitlistEntry::query()->firstOrFail()->status);

        $this->sellOut('0244000222');
        $this->join()->assertCreated();

        $this->assertSame('notified', WaitlistEntry::query()->firstOrFail()->status);
    }

    // ── The collection reminder ───────────────────────────────────────────────

    /**
     * ⚠️ THE MESSAGE A SAME-DAY KITCHEN WOULD NEVER SEND. An order placed on the 1st for the
     * 12th is eleven days out of mind, and she has already cooked it.
     */
    public function test_tomorrows_orders_are_reminded(): void
    {
        $order = $this->sellOut();
        $this->travelTo(CarbonImmutable::parse($this->day->date->toDateString())->subDay()->setTime(17, 0));
        $this->log()->flush();

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $messages = $this->log()->sentTo('+233244000111');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('reminder', $messages[0]['message']);
        $this->assertStringContainsString($order->order_number, $messages[0]['message']);
    }

    /**
     * ⚠️ THE MOST CONFUSING MESSAGE THIS SYSTEM COULD SEND, and it would go to exactly the
     * customers already unhappy about something.
     */
    public function test_a_cancelled_order_is_never_reminded(): void
    {
        $order = $this->sellOut();
        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $this->travelTo(CarbonImmutable::parse($this->day->date->toDateString())->subDay()->setTime(17, 0));
        $this->log()->flush();

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertCount(0, $this->log()->sentTo('+233244000111'));
    }

    /** An unpaid order whose slot was already released is not being cooked. */
    public function test_an_expired_hold_is_never_reminded(): void
    {
        $order = $this->sellOut();
        $order->forceFill(['hold_expired' => true])->save();

        $this->travelTo(CarbonImmutable::parse($this->day->date->toDateString())->subDay()->setTime(17, 0));
        $this->log()->flush();

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertCount(0, $this->log()->sentTo('+233244000111'));
    }

    /** Nothing goes out two days early, or the reminder stops meaning "tomorrow". */
    public function test_nothing_is_reminded_two_days_out(): void
    {
        $this->sellOut();
        $this->travelTo(CarbonImmutable::parse($this->day->date->toDateString())->subDays(2)->setTime(17, 0));
        $this->log()->flush();

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertCount(0, $this->log()->sentTo('+233244000111'));
    }
}
