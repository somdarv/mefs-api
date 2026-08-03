<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ⚠️ THE TAKEOVER (brief §4.3, trap §10.2).
 *
 * In the original, `PATCH /employees/{id}` was gated on one coarse permission a mid-level
 * role held. It accepted any value from the role enum, had no ceiling check and no self-edit
 * guard. **One request with `{"role":"tech_admin"}` was a full platform takeover.**
 *
 * These tests are named after the exploit rather than after the method, so that a failure
 * reads as "the takeover is possible again" rather than "assertion false".
 */
final class RoleCeilingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ── The ceiling ───────────────────────────────────────────────────────────

    public function test_an_admin_cannot_promote_anyone_to_tech_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->customer()->create();

        $this->assertFalse(
            Gate::forUser($admin)->allows('assignRole', [$target, Role::TechAdmin]),
            'THE TAKEOVER IS BACK: an admin just assigned a role above their own.',
        );
    }

    /**
     * Strictly greater, never equal. If an admin can mint another admin, one compromised
     * account multiplies itself and the ceiling stops meaning anything after the first
     * breach.
     */
    public function test_an_admin_cannot_mint_another_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->customer()->create();

        $this->assertFalse(
            Gate::forUser($admin)->allows('assignRole', [$target, Role::Admin]),
            'The ceiling must be strictly greater — an admin minting admins defeats it.',
        );
    }

    public function test_a_tech_admin_may_assign_admin(): void
    {
        $owner = User::factory()->techAdmin()->create();
        $target = User::factory()->customer()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('assignRole', [$target, Role::Admin]));
    }

    /**
     * The ceiling applies to who you act ON, not only to what you grant. Without this an
     * admin could demote a tech_admin to customer and then own the platform.
     */
    public function test_an_admin_cannot_demote_a_tech_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->techAdmin()->create();

        $this->assertFalse(
            Gate::forUser($admin)->allows('assignRole', [$owner, Role::Customer]),
            'An admin just demoted their superior — that is the takeover by another route.',
        );
    }

    // ── The self-edit guard ───────────────────────────────────────────────────

    public function test_nobody_can_change_their_own_role(): void
    {
        foreach ([Role::TechAdmin, Role::Admin] as $role) {
            $user = User::factory()->withRole($role)->create();

            $this->assertFalse(
                Gate::forUser($user)->allows('assignRole', [$user, Role::TechAdmin]),
                "A {$role->value} changed their own role.",
            );
        }
    }

    /**
     * Even downward. A tech_admin who demotes themselves to admin cannot promote themselves
     * back — that is a lockout with no way out, and the only fix is a database edit.
     */
    public function test_a_tech_admin_cannot_demote_themselves(): void
    {
        $owner = User::factory()->techAdmin()->create();

        $this->assertFalse(
            Gate::forUser($owner)->allows('assignRole', [$owner, Role::Admin]),
            'Self-demotion is a lockout: nothing can promote them back.',
        );
    }

    public function test_nobody_can_delete_their_own_account(): void
    {
        $owner = User::factory()->techAdmin()->create();

        $this->assertFalse(Gate::forUser($owner)->allows('delete', $owner));
    }

    // ── Fails closed ──────────────────────────────────────────────────────────

    /**
     * A user with no role assigns nothing. The null case must refuse rather than fall back
     * to some baseline (brief trap §10.1 — a guard that cannot evaluate must not wave
     * through).
     */
    public function test_a_user_with_no_role_can_assign_nothing(): void
    {
        $nobody = User::factory()->create();

        $this->assertNull($nobody->roleEnum());

        foreach (Role::cases() as $role) {
            $this->assertFalse($nobody->mayAssignRole($role));
        }
    }

    public function test_a_customer_holds_no_permissions_at_all(): void
    {
        $customer = User::factory()->customer()->create();

        $this->assertCount(
            0,
            $customer->getAllPermissions(),
            'A customer must hold nothing. Anything here is reachable from the OTP path.',
        );
    }

    /**
     * ⚠️ SHE HOLDS EVERYTHING NOW, AND WHAT MAKES THAT SAFE IS EVERY TEST ABOVE THIS ONE.
     *
     * This assertion used to be the opposite: `admin` was held short of `staff.manage` and
     * `settings.manage` on the argument that a grant "just in case" is how the takeover
     * worked. That argument was about an organisation with layers. This business has two
     * accounts and one person behind both, so withholding a permission restrained nobody —
     * it only meant the shop's own account could not switch its own till off.
     *
     * The takeover was never really about which permission was held. It was
     * `manage_employees` with NO CEILING AND NO SELF-EDIT GUARD, and both of those now
     * exist and are pinned by the tests above — every one of which still passes with
     * `staff.manage` in her hand. That is the point of asserting it here, next to them: an
     * admin holding staff management still cannot promote to tech_admin, still cannot mint
     * another admin, and still cannot touch her own role.
     */
    public function test_the_admin_role_holds_everything_and_the_ceiling_still_binds(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->can('settings.manage'), 'She must be able to switch her own till off.');
        $this->assertTrue($admin->can('staff.manage'));
        $this->assertTrue($admin->can('cycles.manage'), 'She must be able to run her kitchen.');

        // The guard that actually matters, restated where the grant is: holding staff.manage
        // is not the same as being able to use it to climb.
        $this->assertFalse(
            Gate::forUser($admin)->allows('assignRole', [User::factory()->customer()->create(), Role::TechAdmin]),
            'The ceiling must hold even when the permission behind it is held.',
        );
    }
}
