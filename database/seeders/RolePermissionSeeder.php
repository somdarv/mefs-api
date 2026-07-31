<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions — and, critically, the ones that should NOT be held.
 *
 * ⚠️ A SEEDER THAT ONLY ADDS CANNOT FIX AN OVER-GRANTED ROLE (brief §4.4, trap §10.3).
 *
 * This one syncs, so it revokes. Two things make that actually work:
 *
 * 1. `syncPermissions()` on the role removes what the enum no longer lists.
 * 2. **Direct grants are stripped too.** Permission checks resolve through `$user->can()`,
 *    which a direct user-level grant satisfies regardless of role. Revoking from the role
 *    alone leaves an already-escalated account escalated — which is the failure mode that
 *    made this a named trap rather than a tidiness note.
 *
 * Safe to re-run. It is idempotent by construction.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->createPermissions();

        // Flush again between the two steps. syncPermissions() resolves each name through
        // the registrar's cache, which was populated (and is now stale) by the reads inside
        // createPermissions() — without this, seeding a fresh database throws
        // PermissionDoesNotExist for a permission that was created moments earlier.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->syncRoles();
        $this->stripDirectGrants();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createPermissions(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        // Anything in the table that the enum no longer knows about is gone from the code
        // and must not linger as a live grant.
        $known = array_map(fn (PermissionEnum $p) => $p->value, PermissionEnum::cases());

        Permission::query()->whereNotIn('name', $known)->delete();
    }

    private function syncRoles(): void
    {
        foreach (PermissionEnum::byRole() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            // sync, not give: this is the line that revokes.
            $role->syncPermissions(array_map(fn (PermissionEnum $p) => $p->value, $permissions));
        }

        // Same reasoning as permissions — a role the enum dropped should not survive.
        $known = array_map(fn (RoleEnum $r) => $r->value, RoleEnum::cases());

        Role::query()->whereNotIn('name', $known)->delete();
    }

    /**
     * Remove every direct user→permission grant.
     *
     * This system grants exclusively through roles. A direct grant is therefore either a
     * mistake or an escalation, and either way it should not survive a seed. Deleting the
     * pivot rows wholesale is correct *because* of that policy — it would be wrong in a
     * system that legitimately uses direct grants.
     */
    private function stripDirectGrants(): void
    {
        $table = config('permission.table_names.model_has_permissions');

        $count = DB::table($table)->count();

        if ($count > 0) {
            DB::table($table)->delete();

            $this->command?->warn(
                "Stripped {$count} direct permission grant(s). This system grants through "
                .'roles only, so a direct grant is either a mistake or an escalation.',
            );
        }
    }
}
