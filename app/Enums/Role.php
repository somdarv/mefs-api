<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Three roles, not the brief's ten (see ../mefs/CLAUDE.md, "v1 scope").
 *
 * The `rank` is the whole point of this enum. Two of the brief's most expensive traps are
 * privilege escalation through role assignment (§10.2: one `{"role":"tech_admin"}` request
 * was a full takeover), and both are prevented by comparing ranks rather than by listing
 * which roles may assign which. A list has to be edited every time a role is added; a rank
 * comparison does not.
 */
enum Role: string
{
    /** The platform owner. Roles, resets, system settings, maintenance. */
    case TechAdmin = 'tech_admin';

    /** Her. Runs the kitchen: cycles, menu, orders, money. */
    case Admin = 'admin';

    /** Anyone who orders. Holds no staff ability and reaches no staff route. */
    case Customer = 'customer';

    /**
     * Higher outranks lower. Gaps are deliberate — a role can be inserted between two
     * existing ones without renumbering the others.
     */
    public function rank(): int
    {
        return match ($this) {
            self::TechAdmin => 100,
            self::Admin => 50,
            self::Customer => 0,
        };
    }

    /**
     * Whether this role may assign `$target` to someone.
     *
     * **Strictly greater**, never equal. An admin may not mint another admin — otherwise
     * one compromised account multiplies itself, and the ceiling stops meaning anything
     * after the first breach (brief §4.3.2).
     */
    public function mayAssign(self $target): bool
    {
        return $this->rank() > $target->rank();
    }

    /** Staff roles get a token with the `staff` ability; customers never do (brief Law 3). */
    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }

    /** @return list<self> */
    public static function staffRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isStaff()));
    }

    public function label(): string
    {
        return match ($this) {
            self::TechAdmin => 'Platform admin',
            self::Admin => 'Admin',
            self::Customer => 'Customer',
        };
    }
}
