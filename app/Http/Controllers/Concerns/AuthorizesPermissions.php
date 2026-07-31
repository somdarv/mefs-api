<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\Permission;
use Illuminate\Http\Request;

/**
 * One permission check, shared.
 *
 * It was a private method copied into each admin controller, which is how two copies end up
 * differing by a `?->` and one of them starts passing for an unauthenticated caller. There
 * is nothing to get subtly wrong in a single implementation.
 *
 * ⚠️ It fails CLOSED: no user on the request means refused, not skipped. The route group
 * already requires `auth:sanctum`, so a null user here would be a bug — and a guard that
 * shrugs at a bug it did not expect is the original's §10.1 failure exactly.
 */
trait AuthorizesPermissions
{
    protected function authorizePermission(Request $request, Permission $permission): void
    {
        abort_unless(
            $request->user()?->can($permission->value) === true,
            403,
            "This action requires the {$permission->value} permission.",
        );
    }
}
