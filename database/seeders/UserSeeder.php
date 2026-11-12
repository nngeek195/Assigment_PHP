<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::updateOrCreate(
            ['email' => 'manager@quantix.test'],
            [
                'name' => 'John Manager',
                'password' => 'Password@123',
                'role' => UserRole::MANAGER,
                'manager_id' => null,
            ]
        );

        User::updateOrCreate(
            ['email' => 'employee1@quantix.test'],
            [
                'name' => 'Alex Employee',
                'password' => 'Password@123',
                'role' => UserRole::EMPLOYEE,
                'manager_id' => $manager->id,
            ]
        );

        User::updateOrCreate(
            ['email' => 'employee2@quantix.test'],
            [
                'name' => 'Sarah Employee',
                'password' => 'Password@123',
                'role' => UserRole::EMPLOYEE,
                'manager_id' => $manager->id,
            ]
        );

        User::updateOrCreate(
            ['email' => 'operations@quantix.test'],
            [
                'name' => 'David Operations',
                'password' => 'Password@123',
                'role' => UserRole::OPERATIONS,
                'manager_id' => null,
            ]
        );

        User::updateOrCreate(
            ['email' => 'operations2@quantix.test'],
            [
                'name' => 'Michael Operations',
                'password' => 'Password@123',
                'role' => UserRole::OPERATIONS,
                'manager_id' => null,
            ]
        );
    }
}
