<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Test accounts for local development and demos only.
 * Run with: php artisan db:seed --class=UserSeeder
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('UserSeeder refuses to run when APP_ENV=production.');
        }

        $password = env('SEED_PASSWORD');
        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException(
                'SEED_PASSWORD is missing or empty. Set SEED_PASSWORD in services/identity-service/.env before seeding.'
            );
        }

        $hash = Hash::make($password);

        // [email, role, first_name, last_name, branch_id, verification_status]
        $accounts = [
            ['ceo@pharmassist.test', 'ceo', 'Carlos', 'Executive', null, 'approved'],
            ['manager1@pharmassist.test', 'branch_manager', 'Maria', 'Manager', 1, 'approved'],
            ['technician1@pharmassist.test', 'pharmacy_technician', 'Tomas', 'Technician', 1, 'approved'],
            ['assistant1@pharmassist.test', 'pharmacy_assistant', 'Ana', 'Assistant', 1, 'approved'],
            ['customer.approved@pharmassist.test', 'customer', 'Clara', 'Approved', null, 'approved'],
            ['customer.pending@pharmassist.test', 'customer', 'Pedro', 'Pending', null, 'pending'],
        ];

        foreach ($accounts as [$email, $role, $first, $last, $branchId, $status]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'password_hash' => $hash,
                    'role' => $role,
                    'first_name' => $first,
                    'last_name' => $last,
                    'branch_id' => $branchId,
                    'email_verified_at' => now(),
                    'verification_status' => $status,
                    'is_active' => true,
                    'failed_logins' => 0,
                    'locked_until' => null,
                ]
            );
        }
    }
}
