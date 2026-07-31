<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role as RoleEnum;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * One table for staff and customers alike (brief §3.4). See the users migration for why.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * ⚠️ `role` and `permissions` are NOT here, and must never be.
     *
     * Mass assignment is exactly how the original's takeover worked: `PATCH /employees/{id}`
     * accepted any value from the role enum and wrote it (brief §4.3, trap §10.2). Roles are
     * assigned only through `assignRole()`, behind the ceiling check in
     * `App\Policies\UserPolicy`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /**
     * The single role this user holds, as the enum.
     *
     * Spatie allows many roles per user; this system gives exactly one, because the ceiling
     * in `RoleEnum::mayAssign()` compares ranks and "the rank of a set" is not a question
     * with one answer. If multiple roles are ever needed, the ceiling must compare against
     * the *highest* held — until then, one role keeps it unambiguous.
     */
    public function roleEnum(): ?RoleEnum
    {
        $name = $this->roles->first()?->name;

        return $name === null ? null : RoleEnum::tryFrom($name);
    }

    /** Staff get a token with the `staff` ability; customers never do (brief Law 3). */
    public function isStaff(): bool
    {
        return $this->roleEnum()?->isStaff() ?? false;
    }

    /**
     * Whether this user may hand `$target` to somebody.
     *
     * A user with no role may assign nothing — the null case fails closed rather than
     * defaulting to some baseline role (brief trap §10.1: guards must refuse when they
     * cannot evaluate, not wave through).
     */
    public function mayAssignRole(RoleEnum $target): bool
    {
        return $this->roleEnum()?->mayAssign($target) ?? false;
    }
}
