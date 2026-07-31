<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MenuAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function asAdmin(): static
    {
        return $this->forgetAuth()->withToken(
            $this->admin->createToken('staff', ['staff'])->plainTextToken,
        );
    }

    /**
     * Take a permission away from the `admin` ROLE, not from one user.
     *
     * `$user->revokePermissionTo()` removes only a DIRECT grant, and this system grants
     * exclusively through roles — so calling it on a role-derived permission is a silent
     * no-op and the test passes for the wrong reason. (That asymmetry is the same one
     * behind brief trap §10.3, from the other direction.)
     *
     * Revoking from the role also models the real case: a future helper account whose role
     * carries menu.manage but not menu.price.
     */
    private function revokeFromRole(Permission $permission): void
    {
        SpatieRole::findByName('admin', 'web')->revokePermissionTo($permission->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_an_admin_can_list_the_menu(): void
    {
        $this->asAdmin()
            ->getJson('/api/v1/admin/menu/items')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(12, 'data');   // 9 meals + 3 pantry
    }

    public function test_retired_dishes_are_hidden_unless_asked_for(): void
    {
        MenuItem::query()->where('slug', 'waakye')->update(['is_active' => false]);

        $visible = $this->asAdmin()->getJson('/api/v1/admin/menu/items')->json('data');
        $this->assertNotContains('waakye', array_column($visible, 'slug'));

        // She needs to see what she stopped cooking in order to bring it back.
        $all = $this->asAdmin()->getJson('/api/v1/admin/menu/items?include_inactive=1')->json('data');
        $this->assertContains('waakye', array_column($all, 'slug'));
    }

    public function test_an_admin_can_add_a_dish(): void
    {
        $this->asAdmin()
            ->postJson('/api/v1/admin/menu/items', [
                'name' => 'Red Red',
                'category' => 'meal',
                'default_service_weekdays' => [2, 4],
                'options' => [
                    ['option_key' => 'standard', 'label' => 'Standard', 'price' => 4200],
                ],
            ])
            ->assertCreated()                       // 201, not 200 — brief trap §10.12
            ->assertJsonPath('data.slug', 'red-red')
            ->assertJsonPath('data.options.0.price', 4200);

        $this->assertDatabaseHas('menu_items', ['slug' => 'red-red']);
    }

    /**
     * A dish with no option can never be sold, and (in v2) can never be costed or deducted
     * — recipes attach to options. Rejecting it at the boundary is cheaper than discovering
     * it at checkout.
     */
    public function test_a_dish_cannot_be_created_without_at_least_one_option(): void
    {
        $this->asAdmin()
            ->postJson('/api/v1/admin/menu/items', [
                'name' => 'Ghost dish',
                'category' => 'meal',
                'options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['options']]);
    }

    public function test_an_admin_can_change_which_days_a_dish_is_cooked(): void
    {
        $item = MenuItem::query()->where('slug', 'gari-fotor')->firstOrFail();

        $this->asAdmin()
            ->patchJson("/api/v1/admin/menu/items/{$item->id}", [
                'default_service_weekdays' => [1, 5],
            ])
            ->assertOk()
            ->assertJsonPath('data.service_days', [1, 5]);
    }

    public function test_syncing_options_soft_deletes_the_ones_removed(): void
    {
        $item = MenuItem::query()->where('slug', 'shito')->firstOrFail();
        $removed = $item->options()->where('option_key', '250ml')->firstOrFail();

        $this->asAdmin()
            ->putJson("/api/v1/admin/menu/items/{$item->id}/options", [
                'options' => [
                    ['option_key' => '500ml', 'label' => '500ml', 'size_label' => '500ml', 'price' => 8500],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.options');

        // SOFT, never hard: order lines point at this id, and a receipt that cannot resolve
        // its own item is worse than a retired row sitting in the table.
        $this->assertSoftDeleted('menu_item_options', ['id' => $removed->id]);
    }

    public function test_an_admin_can_upload_a_photo(): void
    {
        Storage::fake('public');

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        $this->asAdmin()
            ->post("/api/v1/admin/menu/items/{$item->id}/image", [
                'image' => UploadedFile::fake()->image('waakye.jpg', 800, 600),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNotNull($item->fresh()->image_path);
        Storage::disk('public')->assertExists($item->fresh()->image_path);
    }

    public function test_retiring_a_dish_soft_deletes_rather_than_erasing_it(): void
    {
        $item = MenuItem::query()->where('slug', 'chinkafa')->firstOrFail();

        $this->asAdmin()->deleteJson("/api/v1/admin/menu/items/{$item->id}")->assertOk();

        $this->assertSoftDeleted('menu_items', ['id' => $item->id]);
    }

    // ── Authorisation ─────────────────────────────────────────────────────────

    public function test_a_customer_token_cannot_reach_the_admin_menu(): void
    {
        $customer = User::factory()->customer()->create();

        $this->withToken($customer->createToken('c', ['customer'])->plainTextToken)
            ->getJson('/api/v1/admin/menu/items')
            ->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_reach_the_admin_menu(): void
    {
        $this->getJson('/api/v1/admin/menu/items')->assertUnauthorized();
    }

    /**
     * ⚠️ THE AUTHORITY SPLIT (brief §3.3).
     *
     * Availability is an operational call; price is an ownership call. Someone who can mark
     * a dish sold out must not thereby be able to reprice it — that is exactly the coarse
     * grant §4.3 is about.
     */
    public function test_a_staff_member_without_menu_price_cannot_change_a_price(): void
    {
        $limited = User::factory()->admin()->create();
        $this->revokeFromRole(Permission::MenuPrice);

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        $this->forgetAuth()
            ->withToken($limited->createToken('staff', ['staff'])->plainTextToken)
            ->putJson("/api/v1/admin/menu/items/{$item->id}/options", [
                'options' => [
                    ['option_key' => 'standard', 'label' => 'Standard', 'price' => 100],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(4000, $item->options()->first()->price, 'The price changed anyway.');
    }

    /** The same person CAN still rename the dish — only the price is walled off. */
    public function test_a_staff_member_without_menu_price_can_still_edit_the_dish(): void
    {
        $limited = User::factory()->admin()->create();
        $this->revokeFromRole(Permission::MenuPrice);

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();

        $this->forgetAuth()
            ->withToken($limited->createToken('staff', ['staff'])->plainTextToken)
            ->patchJson("/api/v1/admin/menu/items/{$item->id}", ['description' => 'Cooked the long way.'])
            ->assertOk();
    }
}
