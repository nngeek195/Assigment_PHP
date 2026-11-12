<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\RequestStatusChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class RequestWorkflowService
{
    private const TRANSITIONS = [
        'draft' => ['submitted'],
        'returned' => ['submitted'],
        'submitted' => ['approved', 'rejected', 'returned'],
        'approved' => ['in_progress'],
        'in_progress' => ['completed'],
        'rejected' => [],
        'completed' => [],
    ];

    public function transition(
        ServiceRequest $request,
        User $actor,
        RequestStatus $newStatus,
        ?string $comment = null
    ): ServiceRequest {

        // Save both the updated request and its previous status.
        [$updatedRequest, $fromStatus] = DB::transaction(function () use (
            $request,
            $actor,
            $newStatus,
            $comment
        ) {
            $lockedRequest = ServiceRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $oldStatus = $lockedRequest->status;

            $allowedTransitions = self::TRANSITIONS[$oldStatus->value] ?? [];

            if (!in_array($newStatus->value, $allowedTransitions, true)) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot change status from {$oldStatus->value} to {$newStatus->value}.",
                    ],
                ]);
            }

            $lockedRequest->status = $newStatus;

            if ($newStatus === RequestStatus::SUBMITTED) {
                $lockedRequest->submitted_at = now();
            }

            if ($newStatus === RequestStatus::COMPLETED) {
                $lockedRequest->completed_at = now();
            }

            $lockedRequest->save();

            RequestActivity::create([
                'service_request_id' => $lockedRequest->id,
                'user_id' => $actor->id,
                'action' => 'status_changed',
                'from_status' => $oldStatus->value,
                'to_status' => $newStatus->value,
                'comment' => $comment,
            ]);

            return [
                $lockedRequest,
                $oldStatus->value,
            ];
        });

        // Notify users after the transaction succeeds.
        $updatedRequest->load('employee.manager');

        $recipients = collect();

        if ($newStatus === RequestStatus::SUBMITTED) {
            if ($updatedRequest->employee->manager) {
                $recipients->push($updatedRequest->employee->manager);
            }
        } elseif ($newStatus === RequestStatus::APPROVED) {
            $recipients = User::where(
                'role',
                UserRole::OPERATIONS->value
            )->get();
        } else {
            $recipients->push($updatedRequest->employee);
        }

        $recipients = $recipients->unique('id')->values();

        if ($recipients->isNotEmpty()) {
            Notification::send(
                $recipients,
                new RequestStatusChangedNotification(
                    requestId: $updatedRequest->id,
                    requestTitle: $updatedRequest->title,
                    action: 'status_changed',
                    fromStatus: $fromStatus,
                    toStatus: $newStatus->value,
                    actorName: $actor->name,
                    message: "Request #{$updatedRequest->id} changed to {$newStatus->value}."
                )
            );
        }

        return $updatedRequest->refresh();
    }

    public function availableTransitions(ServiceRequest $request): array
    {
        return self::TRANSITIONS[$request->status->value] ?? [];
    }
}
