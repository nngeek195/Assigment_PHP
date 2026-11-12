<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Manager account
        $manager = User::updateOrCreate(
            ['email' => 'manager@quantix.test'],
            [
                'name' => 'John Manager',
                'password' => Hash::make('Password@123'),
                'role' => UserRole::MANAGER,
                'manager_id' => null,
            ]
        );

        // Employee 1
        User::updateOrCreate(
            ['email' => 'employee1@quantix.test'],
            [
                'name' => 'Alex Employee',
                'password' => Hash::make('Password@123'),
                'role' => UserRole::EMPLOYEE,
                'manager_id' => $manager->id,
            ]
        );

        // Employee 2
        User::updateOrCreate(
            ['email' => 'employee2@quantix.test'],
            [
                'name' => 'Sarah Employee',
                'password' => Hash::make('Password@123'),
                'role' => UserRole::EMPLOYEE,
                'manager_id' => $manager->id,
            ]
        );

        // Operations account
        User::updateOrCreate(
            ['email' => 'operations@quantix.test'],
            [
                'name' => 'David Operations',
                'password' => Hash::make('Password@123'),
                'role' => UserRole::OPERATIONS,
                'manager_id' => null,
            ]
        );
    }
}
