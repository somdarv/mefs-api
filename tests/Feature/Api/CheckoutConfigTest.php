<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\SystemSetting;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckoutConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_it_returns_public_settings_in_the_envelope(): void
    {
        $this->getJson('/api/v1/checkout-config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.currency.code', 'GHS')
            ->assertJsonPath('data.currency.minor_units_per_major', 100)
            ->assertJsonPath('data.settings.delivery_enabled', true);
    }

    /**
     * `is_public` defaults to false, so a setting added later is private until someone
     * deliberately opens it. Adding one can never accidentally leak.
     */
    public function test_private_settings_are_never_exposed(): void
    {
        $response = $this->getJson('/api/v1/checkout-config');

        foreach (['manual_order_hold_minutes', 'delivery_fee_collection'] as $private) {
            $this->assertNull(
                $response->json("data.settings.{$private}"),
                "The private setting '{$private}' leaked to the public endpoint.",
            );
        }
    }

    public function test_a_new_setting_is_private_by_default(): void
    {
        SystemSetting::query()->create([
            'key' => 'some_new_internal_thing',
            'value' => 'secret',
            'cast' => 'string',
        ]);
        SystemSetting::flush();

        $this->assertNull(
            $this->getJson('/api/v1/checkout-config')->json('data.settings.some_new_internal_thing'),
        );
    }

    /**
     * ⚠️ The reason `cast` exists. A bare string store round-trips `false` as the STRING
     * "false", which is truthy — so a disabled service charge quietly applies to every
     * order and nobody notices until the money is wrong.
     */
    public function test_a_false_boolean_stays_false_and_does_not_become_a_truthy_string(): void
    {
        $value = SystemSetting::get('service_charge_enabled');

        $this->assertIsBool($value);
        $this->assertFalse($value);
        $this->assertNotSame('false', $value);
    }

    public function test_integers_come_back_as_integers(): void
    {
        // Money is integer minor units everywhere. 500 pesewas = GHS 5.00.
        $this->assertSame(500, SystemSetting::get('service_charge_cap'));
        $this->assertSame(18, SystemSetting::get('default_cutoff_hour'));
    }

    public function test_json_settings_decode_to_arrays(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], SystemSetting::get('default_service_weekdays'));
    }

    public function test_put_updates_a_value_and_busts_the_cache(): void
    {
        $this->assertFalse(SystemSetting::get('service_charge_enabled'));

        SystemSetting::put('service_charge_enabled', true);

        $this->assertTrue(SystemSetting::get('service_charge_enabled'));
    }

    /**
     * She uses a third-party courier, so the delivery fee is pass-through and NOT revenue.
     * Pinned here because every analytics query in M6 depends on it (brief §5.3).
     */
    public function test_delivery_fee_collection_defaults_to_third_party(): void
    {
        $this->assertSame('third_party', SystemSetting::get('delivery_fee_collection'));
    }
}
