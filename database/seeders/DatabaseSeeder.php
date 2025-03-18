<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create an admin user
        User::firstOrCreate(
            ['email' => 'admin@chaldal.com'],
            [
                'name' => 'Super Admin',
                'phone' => '01700000000', // Use a valid 11-digit phone number
                'password' => Hash::make('password'), // Strong, secure password
                'role' => 'admin',
                'is_active' => true,
                'remember_token' => Str::random(10),
                'phone_verified_at' => now(),
            ]
        );

        // Optional: Create additional admin or manager users
        User::firstOrCreate(
            ['email' => 'manager@chaldal.com'],
            [
                'name' => 'Admin Manager',
                'phone' => '01711111111',
                'password' => Hash::make('password'),
                'role' => 'manager',
                'is_active' => true,
                'remember_token' => Str::random(10),
                'phone_verified_at' => now(),
            ]
        );
    }
}
