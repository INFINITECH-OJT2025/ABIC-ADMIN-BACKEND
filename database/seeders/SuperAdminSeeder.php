<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed super_admin, super_admin_viewer, admin, and accountant users.
     */
    public function run(): void
    {
        $defaultPassword = 'Admin@123';

        // Super Admins
        $superAdmins = [
            [
                'email' => 'abicrealty.krissa@gmail.com',
                'name' => 'Krissa',
                'role' => 'super_admin',
            ],
            [
                'email' => 'infinitechcorp.ph@gmail.com',
                'name' => 'Infinitech Corp',
                'role' => 'super_admin',
            ],
        ];

        // Super Admin Viewers
        $superAdminViewers = [
            [
                'email' => 'abicrealty.rose@gmail.com',
                'name' => 'Rose',
                'role' => 'super_admin_viewer',
            ],
            [
                'email' => 'abicrealtyph@gmail.com',
                'name' => 'ABIC Realty',
                'role' => 'super_admin_viewer',
            ],
            [
                'email' => 'infinitechadv@gmail.com',
                'name' => 'Infinitech Adv',
                'role' => 'super_admin_viewer',
            ],
        ];

        // Admin Users
        $admins = [
            [
                'email' => 'abicrealty.aizle@gmail.com',
                'name' => 'Aizle',
                'role' => 'admin',
            ],
        ];

        // Accountant Users
        $accountants = [];
        // $accountants = [
        //     [
        //         'email' => 'abicrealty.darlene@gmail.com',
        //         'name' => 'Darlene',
        //         'role' => 'accountant',
        //     ],
        // ];

        $allUsers = array_merge(
            $superAdmins,
            $superAdminViewers,
            $admins,
            $accountants
        );

        foreach ($allUsers as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make($defaultPassword),
                    'role' => $userData['role'],
                    'account_status' => 'active',
                    'password_expires_at' => null,
                    'is_password_expired' => false,
                    'last_password_change' => now(),
                ]
            );

            // email_verified_at is not currently in User::$fillable.
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }
}

