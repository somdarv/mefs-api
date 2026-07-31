<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Order matters: roles must exist before any user can be assigned one.
        $this->call([
            RolePermissionSeeder::class,
            SystemSettingSeeder::class,
        ]);

        $this->seedBranch();
        $this->seedStaff();
    }

    /**
     * One kitchen. The table and the pivot exist regardless — see the branches migration
     * for why retrofitting them is what the brief spends a whole section warning about.
     */
    private function seedBranch(): void
    {
        Branch::query()->firstOrCreate(
            ['slug' => 'main-kitchen'],
            [
                'name' => "Mef's Kitchen",
                'address' => 'Accra',              // PLACEHOLDER — confirm before launch
                'phone' => '+233241915464',
                'order_number_prefix' => 'A',
                'is_active' => true,
            ],
        );
    }

    private function seedStaff(): void
    {
        // Local development credentials only. `.env` is gitignored and these accounts are
        // created by a seeder that is not run in production — but if this ever does run
        // somewhere real, the passwords must be changed on first sign-in.
        $accounts = [
            [
                'email' => 'owner@mefs.local',
                'name' => 'Platform Owner',
                'phone' => '0240000001',
                'role' => RoleEnum::TechAdmin,
            ],
            [
                'email' => 'mef@mefs.local',
                'name' => 'Mef',
                'phone' => '0240000002',
                'role' => RoleEnum::Admin,
            ],
        ];

        foreach ($accounts as $account) {
            $user = User::query()->firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'phone' => $account['phone'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            // syncRoles, not assignRole: re-seeding must correct a wrong role rather than
            // stack a second one on top of it. Same reasoning as RolePermissionSeeder.
            $user->syncRoles([$account['role']->value]);
        }
    }
}
