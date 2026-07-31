<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⚠️ A PERMISSION NOBODY CHECKS (brief §4.3.4).
 *
 * The original had `view_analytics` granted to exactly the right roles and referenced by
 * **zero** routes, while analytics actually sat behind `view_orders` — which cashiers,
 * kitchen and riders all held. The permission looked enforced. It was decoration.
 *
 * That mistake is invisible in review: the seeder reads correctly, the role list reads
 * correctly, and nothing errors. Only a test that greps the codebase can catch it.
 */
final class PermissionCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Permissions not yet referenced anywhere, listed deliberately.
     *
     * A name here is a promise that the feature is coming, not an excuse. Delete the entry
     * when the route lands — the test fails if a listed permission IS referenced, so this
     * list cannot rot into a permanent allowlist.
     *
     * @var list<string>
     */
    private const DELIBERATELY_UNUSED = [
        // M4/M5 — ordering and the back office.
        'orders.view', 'orders.create', 'orders.advance', 'orders.cancel', 'orders.refund',
        // M6 — money.
        'payments.view', 'analytics.view',
        // M7 — the rest. (staff.view / staff.manage are already enforced by UserPolicy.)
        'customers.view', 'settings.manage', 'audit.view',
    ];

    /**
     * Matches on the ENUM reference (`Permission::MenuView`), not the string value.
     *
     * Two reasons, both learned the hard way in this very test:
     *
     *  - Matching string literals MISSED every real usage, because the controllers pass the
     *    enum case. A coverage test that under-detects reports working permissions as
     *    unenforced, and people learn to ignore it.
     *  - It also produced a FALSE PASS: `cycles.override` looked referenced because a route
     *    is *named* `cycles.override`. A route name is not an authorisation check.
     *
     * Requiring the enum is stricter and correct — a permission referenced only as a bare
     * string is a smell anyway, since it dodges the type system.
     */
    public function test_every_permission_is_referenced_or_deliberately_listed(): void
    {
        $source = $this->sourceText();

        $unreferenced = [];

        foreach (Permission::cases() as $permission) {
            if (substr_count($source, "Permission::{$permission->name}") === 0) {
                $unreferenced[] = $permission->value;
            }
        }

        $undeclared = array_diff($unreferenced, self::DELIBERATELY_UNUSED);

        $this->assertSame([], array_values($undeclared), sprintf(
            "These permissions are granted but checked NOWHERE: %s\n".
            'That is brief §4.3.4 — a permission that looks enforced and is not. Either '.
            'reference it in a route or policy, or add it to DELIBERATELY_UNUSED with the '.
            'milestone that will use it.',
            implode(', ', $undeclared),
        ));
    }

    /**
     * The other direction, so the allowlist cannot rot. If a permission is now referenced
     * but still listed as unused, the list is lying about the state of the code.
     */
    public function test_the_deliberately_unused_list_has_no_stale_entries(): void
    {
        $source = $this->sourceText();

        $stale = array_values(array_filter(
            self::DELIBERATELY_UNUSED,
            fn (string $value) => substr_count($source, 'Permission::'.Permission::from($value)->name) > 0,
        ));

        $this->assertSame([], $stale, sprintf(
            'These are now referenced in code but still listed as unused: %s. Remove them '.
            'from DELIBERATELY_UNUSED.',
            implode(', ', $stale),
        ));
    }

    public function test_every_role_in_the_enum_gets_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (Role::cases() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role->value]);
        }
    }

    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(count(Role::cases()), \DB::table('roles')->count());
        $this->assertSame(count(Permission::cases()), \DB::table('permissions')->count());
    }

    /**
     * ⚠️ REVOKING IS A SEPARATE JOB FROM SEEDING (brief §4.4, trap §10.3).
     *
     * A seeder that only ever adds cannot fix an over-granted role. Worse: permission checks
     * resolve through `$user->can()`, which a DIRECT user-level grant satisfies regardless of
     * role — so revoking from the role alone leaves an already-escalated account escalated.
     */
    public function test_seeding_strips_direct_permission_grants(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $customer = User::factory()->customer()->create();

        // Simulate the escalation: a permission granted straight to the user.
        $customer->givePermissionTo('settings.manage');
        $this->assertTrue($customer->fresh()->can('settings.manage'));

        // Re-seeding must take it away again.
        $this->seed(RolePermissionSeeder::class);

        $this->assertFalse(
            $customer->fresh()->can('settings.manage'),
            'A direct grant survived re-seeding. Revoking from the role alone is not '.
            'enough — $user->can() is satisfied by a direct grant (brief trap §10.3).',
        );
    }

    /** Every PHP file under app/ and routes/, concatenated. */
    private function sourceText(): string
    {
        $files = array_merge(
            File::allFiles(app_path()),
            File::allFiles(base_path('routes')),
        );

        $text = '';

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip the enum itself — declaring a case is not checking it.
            if (str_ends_with($file->getPathname(), 'Enums'.DIRECTORY_SEPARATOR.'Permission.php')) {
                continue;
            }

            $text .= $file->getContents();
        }

        return $text;
    }
}
