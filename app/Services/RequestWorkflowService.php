<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestWorkflowService
{
    /**
     * Define all valid request status transitions.
     */
    private const TRANSITIONS = [
        'draft' => ['submitted'],
        'returned' => ['submitted'],
        'submitted' => ['approved', 'rejected', 'returned'],
        'approved' => ['in_progress'],
        'in_progress' => ['completed'],
        'rejected' => [],
        'completed' => [],
    ];

    /**
     * Change the status of a service request.
     */
    public function transition(
        ServiceRequest $request,
        User $actor,
        RequestStatus $newStatus,
        ?string $comment = null
    ): ServiceRequest {

        return DB::transaction(function () use (
            $request,
            $actor,
            $newStatus,
            $comment
        ) {
            // Lock the database record during the transition.
            $request = ServiceRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $currentStatus = $request->status;

            // Check whether the requested transition is allowed.
            $allowedTransitions = self::TRANSITIONS[
                $currentStatus->value
            ] ?? [];

            if (!in_array($newStatus->value, $allowedTransitions, true)) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Cannot change status from {$currentStatus->value} to {$newStatus->value}.",
                    ],
                ]);
            }

            // Update request status.
            $request->status = $newStatus;

            if ($newStatus === RequestStatus::SUBMITTED) {
                $request->submitted_at = now();
            }

            if ($newStatus === RequestStatus::COMPLETED) {
                $request->completed_at = now();
            }

            $request->save();

            // Store the activity history.
            RequestActivity::create([
                'service_request_id' => $request->id,
                'user_id' => $actor->id,
                'action' => 'status_changed',
                'from_status' => $currentStatus->value,
                'to_status' => $newStatus->value,
                'comment' => $comment,
            ]);

            return $request->refresh();
        });
    }

    /**
     * Return all valid next statuses.
     */
    public function availableTransitions(
        ServiceRequest $request
    ): array {
        return self::TRANSITIONS[$request->status->value] ?? [];
    }
}
