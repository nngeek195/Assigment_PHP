<?php

namespace Database\Seeders;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class ServiceRequestSeeder extends Seeder
{
    public function run(): void
    {
        $employee1 = User::where('email', 'employee1@quantix.test')->firstOrFail();
        $employee2 = User::where('email', 'employee2@quantix.test')->firstOrFail();

        $operations1 = User::where('email', 'operations@quantix.test')->firstOrFail();
        $operations2 = User::where('email', 'operations2@quantix.test')->firstOrFail();

        $requests = [
            [
                'employee' => $employee1,
                'title' => 'Development Laptop Request',
                'description' => 'Requesting a laptop for software development work.',
                'category' => 'IT Equipment',
                'status' => RequestStatus::DRAFT,
                'assigned_to' => null,
            ],
            [
                'employee' => $employee2,
                'title' => 'Access to Project Repository',
                'description' => 'Need access to the internal project repository.',
                'category' => 'Software Access',
                'status' => RequestStatus::SUBMITTED,
                'assigned_to' => null,
            ],
            [
                'employee' => $employee1,
                'title' => 'Additional Monitor',
                'description' => 'Requesting an additional monitor for development.',
                'category' => 'IT Equipment',
                'status' => RequestStatus::APPROVED,
                'assigned_to' => null,
            ],
            [
                'employee' => $employee2,
                'title' => 'VPN Access',
                'description' => 'Requesting VPN access for remote work.',
                'category' => 'Network Access',
                'status' => RequestStatus::APPROVED,
                'assigned_to' => $operations1->id,
            ],
            [
                'employee' => $employee1,
                'title' => 'Software Installation',
                'description' => 'Requesting installation of approved development software.',
                'category' => 'Software',
                'status' => RequestStatus::IN_PROGRESS,
                'assigned_to' => $operations1->id,
            ],
            [
                'employee' => $employee2,
                'title' => 'Network Connectivity Issue',
                'description' => 'Experiencing network connectivity problems.',
                'category' => 'Network',
                'status' => RequestStatus::COMPLETED,
                'assigned_to' => $operations2->id,
            ],
            [
                'employee' => $employee1,
                'title' => 'Database Access',
                'description' => 'Requesting access to the development database.',
                'category' => 'Database Access',
                'status' => RequestStatus::RETURNED,
                'assigned_to' => null,
            ],
            [
                'employee' => $employee2,
                'title' => 'Printer Replacement',
                'description' => 'Requesting replacement of a damaged printer.',
                'category' => 'Office Equipment',
                'status' => RequestStatus::REJECTED,
                'assigned_to' => null,
            ],
            [
                'employee' => $employee1,
                'title' => 'Email Account Configuration',
                'description' => 'Requesting configuration of a work email account.',
                'category' => 'IT Support',
                'status' => RequestStatus::SUBMITTED,
                'assigned_to' => null,
            ],
            [
                'employee' => $employee2,
                'title' => 'Keyboard Replacement',
                'description' => 'Requesting a replacement keyboard.',
                'category' => 'IT Equipment',
                'status' => RequestStatus::DRAFT,
                'assigned_to' => null,
            ],
        ];

        foreach ($requests as $index => $data) {
            $status = $data['status'];

            ServiceRequest::updateOrCreate(
                [
                    'employee_id' => $data['employee']->id,
                    'title' => $data['title'],
                ],
                [
                    'description' => $data['description'],
                    'category' => $data['category'],
                    'status' => $status,
                    'assigned_to' => $data['assigned_to'],
                    'submitted_at' => in_array($status, [
                        RequestStatus::SUBMITTED,
                        RequestStatus::APPROVED,
                        RequestStatus::REJECTED,
                        RequestStatus::RETURNED,
                        RequestStatus::IN_PROGRESS,
                        RequestStatus::COMPLETED,
                    ], true) ? now()->subDays($index + 1) : null,
                    'completed_at' => $status === RequestStatus::COMPLETED
                        ? now()->subDay()
                        : null,
                ]
            );
        }
    }
}
