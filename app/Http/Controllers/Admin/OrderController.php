<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Rules\PhoneNumber;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\IllegalTransition;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderRefused;
use App\Services\Ordering\OrderStatusMachine;
use App\Support\GhanaPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The back office's order desk.
 *
 * ⚠️ `store()` IS MANUAL ENTRY, AND IT GOES THROUGH `OrderCreator` LIKE EVERYTHING ELSE.
 *
 * This is the endpoint the brief warns about. In the original the till had its own creation
 * route, the stock gate went on the *other* one, and the gate was live and inert at the same
 * time — 23 portions sold against a balance of 6, four minutes after it deployed (§5.8,
 * §10.9). There is nothing here that decides whether an order may be placed; it builds the
 * same `OrderDraft` the customer path builds and hands it to the same service.
 *
 * She gets no per-order bypass of the gate. Reopening a closed week is `force_open` on the
 * cycle — permissioned, logged, with a reason — which then applies to customers too, because
 * "we are open" is not a per-order fact.
 */
final class OrderController extends Controller
{
    use AuthorizesPermissions;

    public function __construct(
        private readonly OrderCreator $creator,
        private readonly OrderStatusMachine $statuses,
    ) {}

    /**
     * The pipeline, filtered.
     *
     * Defaults matter here: with no filter this returns orders that are still LIVE, not
     * every order ever placed. A list that opens on six months of completed orders is a
     * list nobody reads.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::OrdersView);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(OrderStatus::values())],
            'fulfil_date' => ['nullable', 'date_format:Y-m-d'],
            'order_cycle_id' => ['nullable', 'integer'],

            // The delivery run sheet's whole filter. Here rather than in the browser: a run
            // sheet that fetches every order and hides most of them still pages at 50 and
            // silently drops the deliveries on page 2.
            'order_type' => ['nullable', Rule::in(array_column(OrderType::cases(), 'value'))],

            'search' => ['nullable', 'string', 'max:60'],
            'include_finished' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with(['items'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['fulfil_date'] ?? null, fn ($q, $date) => $q->whereDate('fulfil_date', $date))
            ->when($filters['order_cycle_id'] ?? null, fn ($q, $id) => $q->where('order_cycle_id', $id))
            ->when($filters['order_type'] ?? null, fn ($q, $type) => $q->where('order_type', $type))
            ->when(
                ($filters['status'] ?? null) === null && ! ($filters['include_finished'] ?? false),
                fn ($q) => $q->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value]),
            )
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                function ($sub) use ($search) {
                    $sub->where('order_number', 'ilike', "%{$search}%")
                        ->orWhere('contact_name', 'ilike', "%{$search}%")
                        ->orWhere('contact_phone', 'ilike', "%{$search}%");

                    // ⚠️ Phones are STORED in E.164, and she will type what the customer
                    // told her: "0244 000 111". A raw LIKE on the typed form matches
                    // nothing, and "search is broken" is what she would report — not "your
                    // normalisation is asymmetric". Normalise the needle too.
                    $e164 = GhanaPhone::normalise($search);

                    if ($e164 !== null) {
                        $sub->orWhere('contact_phone', $e164);
                    }
                },
            ))
            // Oldest first inside a day: the kitchen works a queue, not a stack.
            ->orderBy('fulfil_date')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::success([
            'orders' => StaffOrderResource::collection($orders->items()),
            'meta' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ], 'Orders');
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, Permission::OrdersView);

        return ApiResponse::success(
            new StaffOrderResource($order->load(['items', 'statusHistory'])),
            "Order {$order->order_number}",
        );
    }

    /**
     * Manual entry: an order taken by phone or over WhatsApp.
     *
     * The only things this path gets that the customer path does not are its `source`, a
     * staff note, and the longer unpaid hold that follows from the source (departure #6).
     * Everything else — the gate, the money, the branch, the numbering — is the shared
     * service's business.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::OrdersCreate);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:60'],
            'lines.*.menu_item_option_id' => ['required', 'integer', 'exists:menu_item_options,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.notes' => ['nullable', 'string', 'max:280'],

            'order_type' => ['required', Rule::in(array_column(OrderType::cases(), 'value'))],

            // Online is not offered here. An order she takes by hand is `phone` or
            // `whatsapp` — letting this endpoint mint an `online` order would put a
            // manually-entered sale into the customer-acquisition numbers.
            'source' => ['required', Rule::in([OrderSource::Phone->value, OrderSource::Whatsapp->value])],

            'cycle_day_id' => ['nullable', 'integer', 'exists:cycle_days,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],

            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', new PhoneNumber],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'delivery_area' => ['nullable', 'string', 'max:120'],
            'gps_code' => ['nullable', 'string', 'max:40'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', 'string', 'max:40'],
        ]);

        $draft = new OrderDraft(
            lines: BasketLine::listFrom($data['lines']),
            type: OrderType::from($data['order_type']),
            source: OrderSource::from($data['source']),
            contactName: $data['contact_name'],
            contactPhone: $data['contact_phone'],
            cycleDayId: $data['cycle_day_id'] ?? null,
            customerId: $data['customer_id'] ?? null,
            deliveryAddress: $data['delivery_address'] ?? null,
            deliveryArea: $data['delivery_area'] ?? null,
            gpsCode: $data['gps_code'] ?? null,
            deliveryNote: $data['delivery_note'] ?? null,
            internalNotes: $data['internal_notes'] ?? null,
            paymentMethod: $data['payment_method'] ?? 'mobile_money',
            actor: $request->user(),
        );

        try {
            $order = $this->creator->create($draft);
        } catch (OrderRefused $refused) {
            return ApiResponse::error($refused->getMessage(), 422, [
                'order' => [$refused->getMessage()],
                'refusal' => $refused->context(),
            ]);
        }

        return ApiResponse::created(
            new StaffOrderResource($order->load(['items', 'statusHistory'])),
            "Order {$order->order_number} entered",
        );
    }

    /**
     * The paperwork, and nothing else.
     *
     * ⚠️ THE VALIDATED LIST IS THE POINT OF THIS ENDPOINT.
     *
     * Three fields: who is carrying it, their reference, and her note to herself. Money is
     * not here, status is not here, and the customer's name and phone are not here — each
     * has its own path, and each of those paths does something this one does not. Status
     * goes through the machine and writes an audit row. Money is computed. A contact change
     * is a change to what the customer agreed to.
     *
     * An "update the order" endpoint that accepted the model's whole `$fillable` would be
     * exactly the coarse grant the brief spends §4.3 on: a permission to edit a courier
     * name that turns out to be a permission to edit everything the endpoint happens to
     * accept.
     *
     * `orders.advance` rather than `orders.view`, because this writes. It is the permission
     * that means "you work this order", which is the same sentence as assigning its courier.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, Permission::OrdersAdvance);

        $data = $request->validate([
            'courier_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'courier_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $order->fill($data)->save();

        return ApiResponse::success(
            new StaffOrderResource($order->load(['items', 'statusHistory'])),
            "Order {$order->order_number} updated",
        );
    }

    /**
     * Move an order along.
     *
     * Cancelling is a different permission from advancing, checked before the machine is
     * touched: "kitchen staff may mark food ready" and "kitchen staff may cancel a paid
     * order" are not the same sentence.
     */
    public function transition(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(OrderStatus::values())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $to = OrderStatus::from($data['status']);

        $this->authorizePermission(
            $request,
            $to === OrderStatus::Cancelled ? Permission::OrdersCancel : Permission::OrdersAdvance,
        );

        try {
            $this->statuses->transition($order, $to, $request->user(), $data['note'] ?? null);
        } catch (IllegalTransition $refused) {
            // 422, not 500. A refused transition is a well-formed request that the machine
            // will not honour, and the client needs the sentence to show.
            return ApiResponse::error($refused->getMessage(), 422, $refused->toErrorBag());
        }

        return ApiResponse::success(
            new StaffOrderResource($order->load(['items', 'statusHistory'])),
            "Order {$order->order_number} is {$order->status->label()}",
        );
    }

    /**
     * Reject a cancellation request and put the order back where it was.
     *
     * Its own route rather than a status in `transition()`, because the destination is not
     * the caller's to choose — it comes from the stored `cancel_previous_status` (brief
     * §5.4).
     */
    public function rejectCancellation(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, Permission::OrdersCancel);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->statuses->rejectCancellation($order, $request->user(), $data['note'] ?? null);
        } catch (IllegalTransition $refused) {
            return ApiResponse::error($refused->getMessage(), 422, $refused->toErrorBag());
        }

        return ApiResponse::success(
            new StaffOrderResource($order->load(['items', 'statusHistory'])),
            "Order {$order->order_number} is back to {$order->status->label()}",
        );
    }
}
