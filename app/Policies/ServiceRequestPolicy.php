<?php

namespace App\Policies;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceRequest $request): bool
    {
        return match ($user->role) {
            UserRole::EMPLOYEE =>
                $request->employee_id === $user->id,

            UserRole::MANAGER =>
                $request->employee?->manager_id === $user->id,

            UserRole::OPERATIONS =>
                in_array($request->status, [
                    RequestStatus::APPROVED,
                    RequestStatus::IN_PROGRESS,
                    RequestStatus::COMPLETED,
                ], true),

            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::EMPLOYEE;
    }

    public function update(User $user, ServiceRequest $request): bool
    {
        return $user->role === UserRole::EMPLOYEE
            && $request->employee_id === $user->id
            && in_array($request->status, [
                RequestStatus::DRAFT,
                RequestStatus::RETURNED,
            ], true);
    }

    public function submit(User $user, ServiceRequest $serviceRequest): bool
        {
            return $user->role === UserRole::EMPLOYEE
                && $serviceRequest->employee_id === $user->id;
        }

    public function decide(User $user, ServiceRequest $request): bool
    {
        return $user->role === UserRole::MANAGER
            && $request->employee?->manager_id === $user->id
            && $request->status === RequestStatus::SUBMITTED;
    }

    public function assign(User $user, ServiceRequest $request): bool
    {
        return $user->role === UserRole::OPERATIONS
            && $request->status === RequestStatus::APPROVED;
    }

    public function updateStatus(User $user, ServiceRequest $request): bool
    {
        return $user->role === UserRole::OPERATIONS
            && in_array($request->status, [
                RequestStatus::APPROVED,
                RequestStatus::IN_PROGRESS,
            ], true);
    }

    public function comment(User $user, ServiceRequest $request): bool
    {
        return $request->employee_id === $user->id
            || $request->employee?->manager_id === $user->id
            || $request->assigned_to === $user->id;
    }
}
