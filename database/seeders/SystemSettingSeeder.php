<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * The runtime settings (brief §5.2), with their launch defaults.
 *
 * `firstOrCreate` on the key, so re-seeding never overwrites a value she has changed. The
 * seeder owns which settings *exist*; she owns what they *are*.
 */
class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->settings() as $setting) {
            SystemSetting::query()->firstOrCreate(
                ['key' => $setting['key']],
                $setting,
            );
        }

        SystemSetting::flush();
    }

    /** @return list<array<string, mixed>> */
    private function settings(): array
    {
        return [
            // ── Service charge ────────────────────────────────────────────────
            // It is a SERVICE CHARGE, not a tax. The original started with "tax 2.5%" and
            // had to migrate away — calling this a tax is a compliance statement you do not
            // want to make by accident (brief §5.2, trap §10.13).
            [
                'key' => 'service_charge_enabled',
                'value' => '0',
                'cast' => 'bool',
                'group' => 'pricing',
                'is_public' => true,
                'description' => 'Off at launch. A service charge, never called a tax.',
            ],
            [
                'key' => 'service_charge_percent',
                'value' => '0',
                'cast' => 'int',
                'group' => 'pricing',
                'is_public' => true,
                'description' => 'Percent of subtotal, applied only when enabled.',
            ],
            [
                'key' => 'service_charge_cap',
                'value' => '500',
                'cast' => 'int',
                'group' => 'pricing',
                'is_public' => true,
                'description' => 'Cap in pesewas. 500 = GHS 5.00.',
            ],

            // ── Delivery ──────────────────────────────────────────────────────
            // She uses a third-party courier, so the fee is PASS-THROUGH: she collects it
            // and hands it over. It is not revenue, and analytics must exclude it or every
            // revenue figure in the business is overstated (brief §5.3).
            [
                'key' => 'delivery_enabled',
                'value' => '1',
                'cast' => 'bool',
                'group' => 'delivery',
                'is_public' => true,
                'description' => 'Whether delivery may be chosen at checkout.',
            ],
            [
                'key' => 'delivery_fee_default',
                'value' => '2000',
                'cast' => 'int',
                'group' => 'delivery',
                'is_public' => true,
                'description' => 'Flat fee in pesewas. 2000 = GHS 20.00. PLACEHOLDER.',
            ],
            [
                'key' => 'delivery_fee_collection',
                'value' => 'third_party',
                'cast' => 'string',
                'group' => 'delivery',
                'is_public' => false,
                'description' => 'third_party = pass-through, excluded from revenue. self = income.',
            ],

            // ── Ordering defaults ─────────────────────────────────────────────
            // Defaults for a NEW cycle only. Once a cycle exists, its own windows are the
            // truth — these never override it.
            [
                'key' => 'default_cutoff_hour',
                'value' => '18',
                'cast' => 'int',
                'group' => 'ordering',
                'is_public' => true,
                'description' => 'Orders for a date close at this hour the day before.',
            ],
            [
                'key' => 'default_service_weekdays',
                'value' => '[1,2,3,4,5]',
                'cast' => 'json',
                'group' => 'ordering',
                'is_public' => true,
                'description' => 'ISO weekdays pre-filled when creating a cycle. Mon-Fri.',
            ],

            // ── Payments ──────────────────────────────────────────────────────
            /*
             * ⚠️ `simulate` SETTLES ORDERS WITHOUT TAKING MONEY. It exists so the whole
             * lifecycle can be walked — and demonstrated — without a card, and it defaults
             * to `live` so the shop can only ever fall towards actually charging.
             *
             * Everything it touches is marked: the payment row and the order both carry
             * `is_simulated`, `Money\Insights` excludes those orders from every figure, and
             * the back office shows a standing banner while it is on. Fake money that looked
             * real in her takings would be a worse bug than having no simulation at all.
             *
             * `is_public` is FALSE. The storefront has no business knowing, and a customer
             * who could read this could tell whether their card was about to be charged.
             */
            [
                'key' => 'payment_mode',
                'value' => 'live',
                'cast' => 'string',
                'group' => 'payments',
                'is_public' => false,
                'description' => 'live = charge through Paystack. simulate = settle without money, for testing.',
            ],

            // ── Pantry ────────────────────────────────────────────────────────
            // Shelf-stable goods ship nationwide and are NOT tied to a cooking date.
            [
                'key' => 'pantry_shipping_enabled',
                'value' => '1',
                'cast' => 'bool',
                'group' => 'pantry',
                'is_public' => true,
                'description' => 'Pantry-only orders ship independently of any cooking day.',
            ],
            [
                'key' => 'pantry_shipping_fee',
                'value' => '3000',
                'cast' => 'int',
                'group' => 'pantry',
                'is_public' => true,
                'description' => 'Flat nationwide shipping in pesewas. PLACEHOLDER.',
            ],

            // ── Manual orders ─────────────────────────────────────────────────
            // Departure #6: an order she enters by phone holds its slot unpaid, but not
            // forever. At expiry a scheduled job frees the capacity and flags the order, so
            // she never cooks for a no-show she has forgotten about.
            [
                'key' => 'manual_order_hold_minutes',
                'value' => '120',
                'cast' => 'int',
                'group' => 'ordering',
                'is_public' => false,
                'description' => 'How long an unpaid admin-entered order holds its slot.',
            ],

            // The other half of departure #6, and the reason "a customer-placed order that
            // is unpaid never holds a slot" is true rather than merely stated: an online
            // order gets a PAYMENT WINDOW, not a hold. Leave Paystack without paying and
            // the seat is released. Shorter than the manual hold on purpose — nobody needs
            // two hours to finish a mobile-money prompt.
            [
                'key' => 'online_payment_window_minutes',
                'value' => '30',
                'cast' => 'int',
                'group' => 'ordering',
                'is_public' => false,
                'description' => 'How long an unpaid customer order keeps its slot before release.',
            ],
        ];
    }
}
