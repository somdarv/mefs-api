<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * ⚠️ THE MOST EXPLOITABLE SURFACE IN THE SYSTEM (brief §4.3, trap §10.2).
 *
 * In the original, `PATCH /employees/{id}` was gated on a single coarse permission that a
 * mid-level role held. The endpoint accepted any value from the role enum, had no ceiling
 * check and no self-edit guard. **One request with `{"role":"tech_admin"}` was a full
 * platform takeover.**
 *
 * Three separate guards below, because the failure needs all three to be prevented:
 *
 *   1. holding `staff.manage` at all,
 *   2. the role ceiling — never assign at or above your own rank,
 *   3. the self-edit guard — never touch your own role, whatever else you hold.
 *
 * Any one of them alone leaves the hole open. `assignRole()` covers 2 and 3 together, and
 * both are asserted by tests named after the exploit.
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::StaffView->value);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can(Permission::StaffView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::StaffManage->value);
    }

    /**
     * Editing a staff member's ordinary fields — name, email, phone.
     *
     * Notably this does NOT authorise a role change. That is `assignRole()`, deliberately a
     * separate question, so a route that only means to fix a typo can never become an
     * escalation because of what the request body happened to contain.
     */
    public function update(User $actor, User $target): Response
    {
        if (! $actor->can(Permission::StaffManage->value)) {
            return Response::deny('You cannot manage staff accounts.');
        }

        // You may always edit yourself — but see assignRole(): not your own role.
        if ($actor->is($target)) {
            return Response::allow();
        }

        // No editing sideways or upward. Without this, two admins can each rewrite the
        // other, and the ceiling means nothing between peers.
        $targetRole = $target->roleEnum();

        if ($targetRole !== null && ! $actor->mayAssignRole($targetRole)) {
            return Response::deny('You cannot manage an account at or above your own level.');
        }

        return Response::allow();
    }

    /**
     * Give `$target` the role `$role`. THE guarded operation.
     */
    public function assignRole(User $actor, User $target, Role $role): Response
    {
        if (! $actor->can(Permission::StaffManage->value)) {
            return Response::deny('You cannot manage staff accounts.');
        }

        // ── Guard 3: the self-edit guard ──────────────────────────────────────
        // Absolute, and checked before the ceiling. Someone who can assign a role below
        // their own could otherwise demote themselves, and a tech_admin who becomes an
        // admin cannot promote themselves back — a lockout with no way out.
        if ($actor->is($target)) {
            return Response::deny('You cannot change your own role.');
        }

        // ── Guard 2: the role ceiling ─────────────────────────────────────────
        // Strictly greater. An admin may not mint another admin, or one compromised
        // account multiplies itself and the ceiling stops mattering after the first breach.
        if (! $actor->mayAssignRole($role)) {
            return Response::deny('You cannot assign a role at or above your own.');
        }

        // The ceiling applies to who you are acting ON, not only to what you are granting.
        // Without this, an admin could demote a tech_admin to customer and take over.
        $currentRole = $target->roleEnum();

        if ($currentRole !== null && ! $actor->mayAssignRole($currentRole)) {
            return Response::deny('You cannot change the role of an account at or above your own.');
        }

        return Response::allow();
    }

    public function delete(User $actor, User $target): Response
    {
        if ($actor->is($target)) {
            return Response::deny('You cannot delete your own account.');
        }

        return $this->update($actor, $target);
    }
}
