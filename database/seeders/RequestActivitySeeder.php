<?php

namespace Database\Seeders;

use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class RequestActivitySeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::where('email', 'manager@quantix.test')
            ->firstOrFail();

        $operationsActor = User::where('email', 'operations@quantix.test')
            ->firstOrFail();

        $requests = ServiceRequest::with('employee')->get();

        foreach ($requests as $request) {
            $employee = $request->employee;
            $status = $request->status->value;

            $this->record(
                $request,
                $employee,
                'created',
                null,
                'draft',
                'Request created.'
            );

            if ($status === 'draft') {
                continue;
            }

            $this->record(
                $request,
                $employee,
                'status_changed',
                'draft',
                'submitted',
                'Request submitted for review.'
            );

            if ($status === 'submitted') {
                continue;
            }

            if (in_array($status, ['returned', 'rejected'], true)) {
                $this->record(
                    $request,
                    $manager,
                    'status_changed',
                    'submitted',
                    $status,
                    $status === 'returned'
                        ? 'Please provide more information.'
                        : 'Request rejected after review.'
                );

                continue;
            }

            $this->record(
                $request,
                $manager,
                'status_changed',
                'submitted',
                'approved',
                'Request approved.'
            );

            if (! $request->assigned_to) {
                continue;
            }

            $assignee = User::find($request->assigned_to);

            if (! $assignee) {
                continue;
            }

            $this->record(
                $request,
                $operationsActor,
                'assigned',
                'approved',
                'approved',
                'Request assigned to '.$assignee->name.'.'
            );

            if (in_array($status, ['in_progress', 'completed'], true)) {
                $this->record(
                    $request,
                    $assignee,
                    'status_changed',
                    'approved',
                    'in_progress',
                    'Work started.'
                );
            }

            if ($status === 'completed') {
                $this->record(
                    $request,
                    $assignee,
                    'status_changed',
                    'in_progress',
                    'completed',
                    'Work completed.'
                );
            }
        }
    }

    private function record(
        ServiceRequest $request,
        User $actor,
        string $action,
        ?string $from,
        ?string $to,
        ?string $comment = null
    ): void {
        RequestActivity::firstOrCreate([
            'service_request_id' => $request->id,
            'user_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $comment,
        ]);
    }
}
