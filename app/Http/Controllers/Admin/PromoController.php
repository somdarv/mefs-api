<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Enums\PromoScope;
use App\Enums\PromoType;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromoResource;
use App\Http\Responses\ApiResponse;
use App\Models\Promo;
use App\Services\Audit\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Discount codes.
 *
 * ⚠️ `times_used` IS NOT EDITABLE, ANYWHERE ON THIS CONTROLLER. It is the count of
 * redemption rows, and a hand-written value would let a one-shot code be reset by editing a
 * number rather than by deciding to reissue it — with no trace of who decided. The same
 * reasoning as `status` on an order: a counter that can be typed is not a counter.
 *
 * ⚠️ NEITHER IS `code`, AFTER CREATION. Orders snapshot the code as typed, so renaming
 * SUMMER to WINTER would leave last month's receipts pointing at a code that now means
 * something else. Deactivate it and make a new one.
 */
final class PromoController extends Controller
{
    use AuthorizesPermissions;

    public function __construct(private readonly AuditLog $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::PromosView);

        $promos = Promo::query()
            ->withCount('redemptions')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            ['promos' => PromoResource::collection($promos)],
            'Promos',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::PromosManage);

        $data = $request->validate($this->rules());

        $promo = new Promo($data);
        $promo->code = Promo::normaliseCode($data['code']);
        $promo->created_by = $request->user()?->id;

        /*
         * ⚠️ SET HERE RATHER THAN LEFT TO THE COLUMN DEFAULTS.
         *
         * A saved model does not read defaults back from the database, so `scope` would be
         * null on the way out and `PromoResource` would fall over serialising
         * `$promo->scope->value`. Exactly the trap `CheckoutSessionController::store()`
         * already carries a comment about, on a different table.
         */
        $promo->scope ??= PromoScope::All;
        $promo->first_order_only ??= false;
        $promo->is_active ??= true;
        $promo->times_used = 0;

        $promo->save();

        /*
         * A discount code is a money instrument. "20% off, no cap, unlimited uses" costs
         * whatever the week's orders come to, and the terms are the whole story — so the
         * `after` snapshot carries them rather than just the code.
         */
        $this->audit->record(
            action: 'promo.created',
            summary: sprintf('Created %s — %s', $promo->code, $this->terms($promo)),
            actor: $request->user(),
            subject: $promo,
            after: $this->auditable($promo),
        );

        return ApiResponse::created(
            new PromoResource($promo->loadCount('redemptions')),
            "{$promo->code} created",
        );
    }

    public function update(Request $request, Promo $promo): JsonResponse
    {
        $this->authorizePermission($request, Permission::PromosManage);

        // `code` is absent from the rules on update — see the class comment. `sometimes` on
        // everything else so a request that only flips `is_active` does not have to resend
        // the whole promo and risk clearing a field it never mentioned.
        $data = $request->validate($this->rules(creating: false));

        $was = $this->auditable($promo);
        $promo->fill($data)->save();

        [$before, $after] = AuditLog::changes($was, $this->auditable($promo->refresh()));

        if ($after !== []) {
            $this->audit->record(
                action: 'promo.updated',
                summary: sprintf('Edited %s — %s', $promo->code, implode(', ', array_keys($after))),
                actor: $request->user(),
                subject: $promo,
                before: $before,
                after: $after,
            );
        }

        return ApiResponse::success(
            new PromoResource($promo->loadCount('redemptions')),
            "{$promo->code} updated",
        );
    }

    /**
     * ⚠️ A PROMO THAT HAS BEEN USED IS DEACTIVATED, NEVER DELETED.
     *
     * `promo_redemptions` cascades on delete, so removing the row would silently destroy the
     * evidence behind every order that used it — and those orders would keep their
     * `promo_code` snapshot, leaving a receipt naming a code with no record of what it took
     * off or who else used it.
     *
     * An unused promo is a typo, and deleting one is the right thing to do.
     */
    public function destroy(Request $request, Promo $promo): JsonResponse
    {
        $this->authorizePermission($request, Permission::PromosManage);

        if ($promo->redemptions()->exists()) {
            $promo->update(['is_active' => false]);

            return ApiResponse::success(
                new PromoResource($promo->loadCount('redemptions')),
                "{$promo->code} has been used, so it was switched off rather than deleted",
            );
        }

        $promo->delete();

        return ApiResponse::success(null, "{$promo->code} deleted");
    }

    /**
     * ⚠️ THESE RULES MIRROR `promos_value_check` AND MUST STAY IN STEP WITH IT.
     *
     * The constraint is the guarantee; this is the good error message. A percentage of 150
     * caught here says "may not be greater than 100"; caught by the database it says
     * something about a check constraint, on a form she is in the middle of filling in.
     *
     * @return array<string, mixed>
     */
    private function rules(bool $creating = true): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return array_merge($creating ? [
            'code' => ['required', 'string', 'max:32', 'unique:promos,code'],
        ] : [], [
            'description' => ['nullable', 'string', 'max:160'],

            'type' => [$presence, Rule::in(array_column(PromoType::cases(), 'value'))],

            /*
             * ⚠️ THE BOUND DEPENDS ON THE TYPE, AND THE TYPE MAY NOT BE IN THIS REQUEST.
             *
             * On update, `type` can be absent — meaning "leave it as it is" — so the rule
             * falls back to the promo's current type rather than assuming `fixed` and
             * letting a 150% discount through. `max:100` on a percentage; a fixed amount is
             * pesewas and capped at ₵10,000, which is far more than any basket and still
             * catches a cedis-instead-of-pesewas slip.
             */
            'value' => [
                $presence,
                'integer',
                'min:1',
                Rule::when(
                    fn () => request()->input('type', $this->currentType()) === PromoType::Percentage->value,
                    ['max:100'],
                    ['max:1000000'],
                ),
            ],

            /*
             * A cap on a fixed discount is meaningless, and `promos_max_discount_check`
             * refuses to store one. Caught here so the message is about the form rather
             * than about a constraint — `prohibited_if` rather than silently dropping it,
             * because dropping it would mean she thinks she capped something and hasn't.
             */
            'max_discount' => [
                'nullable',
                'integer',
                'min:1',
                Rule::prohibitedIf(
                    fn () => request()->input('type', $this->currentType()) === PromoType::Fixed->value,
                ),
            ],

            'min_subtotal' => ['nullable', 'integer', 'min:1'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            // Nullable means unlimited. `min:1` because a limit of zero is a promo that
            // cannot be used, which is what `is_active: false` is for.
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],

            'scope' => ['sometimes', Rule::in(array_column(PromoScope::cases(), 'value'))],
            'first_order_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** The type on the row being edited, so an absent `type` still bounds `value`. */
    private function currentType(): ?string
    {
        $promo = request()->route('promo');

        return $promo instanceof Promo ? $promo->type->value : null;
    }

    /**
     * The fields worth having a before/after of.
     *
     * `times_used` is absent: it moves on every order, so including it would make every
     * audit row look like an edit and drown the ones that are.
     *
     * @return array<string, mixed>
     */
    private function auditable(Promo $promo): array
    {
        return [
            'type' => $promo->type->value,
            'value' => $promo->value,
            'max_discount' => $promo->max_discount,
            'min_subtotal' => $promo->min_subtotal,
            'scope' => $promo->scope->value,
            'first_order_only' => $promo->first_order_only,
            'usage_limit' => $promo->usage_limit,
            'usage_limit_per_customer' => $promo->usage_limit_per_customer,
            'starts_at' => $promo->starts_at?->toIso8601String(),
            'ends_at' => $promo->ends_at?->toIso8601String(),
            'is_active' => $promo->is_active,
        ];
    }

    /** "20% off, capped at ₵10, meals only" — the summary line, in one sentence. */
    private function terms(Promo $promo): string
    {
        $amount = $promo->type === PromoType::Percentage
            ? "{$promo->value}% off"
            : '₵'.number_format($promo->value / 100, 2).' off';

        $parts = [$amount];

        if ($promo->max_discount !== null) {
            $parts[] = 'capped at ₵'.number_format($promo->max_discount / 100, 2);
        }

        if ($promo->scope !== PromoScope::All) {
            $parts[] = mb_strtolower($promo->scope->label());
        }

        $parts[] = $promo->usage_limit === null ? 'unlimited uses' : "{$promo->usage_limit} uses";

        return implode(', ', $parts);
    }
}
