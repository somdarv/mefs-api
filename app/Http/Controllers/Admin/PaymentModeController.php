<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\Audit\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one switch that decides whether checkout takes money.
 *
 * ── WHY THIS IS ITS OWN ENDPOINT AND NOT A GENERAL SETTINGS CRUD ──────────────
 *
 * A `PUT /admin/settings/{key}` would be less code and would also hand anyone with
 * `settings.manage` a way to write any row in the table — including `pantry_shipping_fee`,
 * `service_charge_cap` and the cutoff hour — through one endpoint with one validation rule
 * that cannot be specific to any of them. This one accepts two strings and nothing else.
 *
 * ⚠️ AND IT IS AUDITED. Turning payment collection off is an act of authority in the exact
 * sense `AuditLog` is for: if the takings look wrong next week, "who put this in simulate
 * mode, and when" is the first question, and it must have an answer that is not a guess.
 */
final class PaymentModeController extends Controller
{
    use AuthorizesPermissions;

    private const MODES = ['live', 'simulate'];

    public function __construct(private readonly AuditLog $audit) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::SettingsManage);

        return ApiResponse::success(
            ['mode' => SystemSetting::get('payment_mode', 'live')],
            'Payment mode',
        );
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::SettingsManage);

        $data = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', self::MODES)],
        ]);

        $before = (string) SystemSetting::get('payment_mode', 'live');

        SystemSetting::query()->updateOrCreate(
            ['key' => 'payment_mode'],
            [
                'value' => $data['mode'],
                'cast' => 'string',
                'group' => 'payments',
                // FALSE. The storefront has no business knowing, and a customer who could
                // read this could tell whether their card was about to be charged.
                'is_public' => false,
                'description' => 'live = charge through Paystack. simulate = settle without money.',
            ],
        );

        SystemSetting::flush();

        if ($before !== $data['mode']) {
            $this->audit->record(
                action: 'payments.mode_changed',
                summary: $data['mode'] === 'simulate'
                    ? 'Payments switched to SIMULATE — checkout stopped taking money'
                    : 'Payments switched to live — checkout is taking money again',
                actor: $request->user(),
                before: ['mode' => $before],
                after: ['mode' => $data['mode']],
            );
        }

        return ApiResponse::success(['mode' => $data['mode']], 'Payment mode updated');
    }
}
