<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CheckoutSessionStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Http\Resources\CheckoutSessionResource;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\CheckoutSession;
use App\Models\CycleDay;
use App\Rules\PhoneNumber;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\BasketQuote;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The basket, and the one way it becomes an order.
 *
 * ⚠️ `confirm()` CALLS `OrderCreator` AND NOTHING ELSE DOES ANYTHING DIFFERENT. Admin manual
 * entry calls the same service with the same draft object. This controller has no gate logic
 * of its own, no capacity check, no price arithmetic — the moment it grows one, the two
 * paths can disagree, and that disagreement is the §5.8 failure verbatim.
 *
 * ── WHO OWNS A BASKET ─────────────────────────────────────────────────────────
 *
 * Most callers here have no account. A guest is identified by `X-Guest-Session`, minted by
 * the server on first contact and echoed back for the client to keep. Both the URL token AND
 * the guest session must match, so a leaked token alone is not enough — and a mismatch
 * returns 404 rather than 403, because confirming that a token exists is itself information.
 */
final class CheckoutSessionController extends Controller
{
    public function __construct(
        private readonly BasketQuote $quote,
        private readonly OrderCreator $creator,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBasket($request, required: true);

        $session = new CheckoutSession;

        $session->token = CheckoutSession::mintToken();
        // Adopt the caller's session if they brought one, mint one if they did not. A
        // client that never sends the header still gets a basket it can come back to.
        $session->guest_session = $this->guestSession($request) ?? Str::random(48);
        $session->customer_id = $request->user()?->customer?->id;
        $session->lines = $data['lines'];
        // Set here rather than left to the column default: a saved model does not read
        // defaults back from the database, so `$session->status` would be null on the way
        // out and the resource would fall over serialising it.
        $session->status = CheckoutSessionStatus::Open;
        $session->expires_at = now()->addHours(CheckoutSession::LIFETIME_HOURS);

        $this->bindDay($session, $data['cycle_day_id'] ?? null);

        $session->save();

        return ApiResponse::created($this->payload($session), 'Basket created');
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $session = $this->find($request, $token);

        return ApiResponse::success($this->payload($session), 'Basket');
    }

    public function update(Request $request, string $token): JsonResponse
    {
        $session = $this->find($request, $token);

        $this->assertOpen($session);

        $data = $this->validateBasket($request, required: false);

        if (array_key_exists('lines', $data)) {
            $session->lines = $data['lines'];
        }

        // `cycle_day_id: null` means "unbind", which is different from not sending the key
        // at all. `array_key_exists`, not `isset` — the two cases are distinguishable and
        // treating them the same makes a pantry cart impossible to switch to shipping.
        if ($request->has('cycle_day_id')) {
            $this->bindDay($session, $data['cycle_day_id'] ?? null);
        }

        $session->save();

        return ApiResponse::success($this->payload($session), 'Basket updated');
    }

    /**
     * Basket → order. The only customer-facing route that creates one.
     *
     * The quote the customer was looking at is advisory; this re-runs the gate under a lock
     * inside `OrderCreator`. Someone else may have taken the last portion in the seconds
     * since the screen rendered, and the answer to that has to be "no" rather than "we
     * already showed them a total".
     */
    public function confirm(Request $request, string $token): JsonResponse
    {
        $session = $this->find($request, $token);

        $this->assertOpen($session);

        $data = $request->validate([
            'order_type' => ['required', Rule::in(array_column(OrderType::cases(), 'value'))],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', new PhoneNumber],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'delivery_area' => ['nullable', 'string', 'max:120'],
            'gps_code' => ['nullable', 'string', 'max:40'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', 'string', 'max:40'],
        ]);

        $draft = new OrderDraft(
            lines: BasketLine::listFrom($session->lines ?? []),
            type: OrderType::from($data['order_type']),

            // ⚠️ NOT FROM THE REQUEST. A customer-placed order is `online` by definition;
            // letting the body name its own source would let anyone mint an order that
            // claims to be manual entry — and manual entry is the one that may hold a slot
            // unpaid for two hours (departure #6).
            source: OrderSource::Online,

            contactName: $data['contact_name'],
            contactPhone: $data['contact_phone'],
            cycleDayId: $session->cycle_day_id,
            customerId: $session->customer_id,
            deliveryAddress: $data['delivery_address'] ?? null,
            deliveryArea: $data['delivery_area'] ?? null,
            gpsCode: $data['gps_code'] ?? null,
            deliveryNote: $data['delivery_note'] ?? null,
            // internal_notes is absent, deliberately: it is staff-only, and there is no
            // field here for a customer to write into it.
            paymentMethod: $data['payment_method'] ?? 'mobile_money',
        );

        try {
            $order = $this->creator->create($draft);
        } catch (OrderRefused $refused) {
            // The gate's whole verdict travels out, not just a sentence — the storefront
            // switches on `reason` to decide whether to offer another day or another dish.
            return ApiResponse::error($refused->getMessage(), 422, [
                'order' => [$refused->getMessage()],
                'refusal' => $refused->context(),
            ]);
        }

        $session->status = CheckoutSessionStatus::Confirmed;
        $session->save();

        return ApiResponse::created(
            new OrderResource($order->load(['items', 'statusHistory'])),
            "Order {$order->order_number} placed",
        );
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validateBasket(Request $request, bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return $request->validate([
            'lines' => [$presence, 'array', 'min:1', 'max:60'],
            'lines.*.menu_item_option_id' => ['required', 'integer', 'exists:menu_item_options,id'],
            // Capped low on purpose. A basket asking for 10,000 portions is not a customer.
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.notes' => ['nullable', 'string', 'max:280'],
            'cycle_day_id' => ['nullable', 'integer', 'exists:cycle_days,id'],
        ]);
    }

    /**
     * Bind the basket to a cooking day, or unbind it.
     *
     * `order_cycle_id` is copied from the day rather than accepted from the client — the day
     * knows which cycle it belongs to, and a request that could name both could name a
     * mismatched pair (brief Law 2).
     */
    private function bindDay(CheckoutSession $session, ?int $cycleDayId): void
    {
        if ($cycleDayId === null) {
            $session->cycle_day_id = null;
            $session->order_cycle_id = null;

            return;
        }

        $day = CycleDay::query()->findOrFail($cycleDayId);

        $session->cycle_day_id = $day->id;
        $session->order_cycle_id = $day->order_cycle_id;
    }

    /**
     * ⚠️ 404, NOT 403, ON A MISMATCH.
     *
     * A 403 confirms the token is real. For an unauthenticated surface where the token is
     * the whole credential, that is the one bit of information worth not giving away.
     */
    private function find(Request $request, string $token): CheckoutSession
    {
        $session = CheckoutSession::query()
            ->with('cycleDay.cycle')
            ->where('token', $token)
            ->first();

        abort_if($session === null, 404, 'Not found.');

        abort_unless(
            $session->belongsToCaller($request->user()?->customer?->id, $this->guestSession($request)),
            404,
            'Not found.',
        );

        return $session;
    }

    private function assertOpen(CheckoutSession $session): void
    {
        // A confirmed session cannot confirm twice, and an expired one cannot be revived.
        // Both are 409: the request was well-formed, the basket is simply past it.
        abort_unless($session->isOpen(), 409, $session->status === CheckoutSessionStatus::Confirmed
            ? 'This basket has already been placed as an order.'
            : 'This basket has expired. Start again.');
    }

    private function guestSession(Request $request): ?string
    {
        $value = $request->header('X-Guest-Session');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function payload(CheckoutSession $session): CheckoutSessionResource
    {
        $session->load('cycleDay.cycle');

        return new CheckoutSessionResource($session, $this->quote->for($session));
    }
}
