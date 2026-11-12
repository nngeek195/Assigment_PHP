<?php

namespace App\Http\Controllers\API;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignServiceRequestRequest;
use App\Http\Requests\DecideServiceRequestRequest;
use App\Http\Requests\StoreRequestCommentRequest;
use App\Http\Requests\StoreServiceRequestRequest;
use App\Http\Requests\UpdateServiceRequestRequest;
use App\Http\Requests\UpdateServiceRequestStatusRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Notifications\RequestStatusChangedNotification;
use Illuminate\Support\Facades\Notification;
use App\Http\Resources\RequestActivityResource;


class ServiceRequestController extends Controller
{
    
    public function __construct(
        private RequestWorkflowService $workflow
    ) {}

    public function activities(ServiceRequest $serviceRequest)
    {
    Gate::authorize('view', $serviceRequest);

    $activities = $serviceRequest->activities()
        ->with('user')
        ->latest()
        ->paginate(20);

    return RequestActivityResource::collection($activities);
    }
    

    public function index(Request $request)
    {
        $user = $request->user();

        $query = ServiceRequest::query()
            ->with(['employee', 'assignedUser'])
            ->latest();

        if ($user->role === UserRole::EMPLOYEE) {
            $query->where('employee_id', $user->id);
        } elseif ($user->role === UserRole::MANAGER) {
            $query->whereHas('employee', function ($employeeQuery) use ($user) {
                $employeeQuery->where('manager_id', $user->id);
            });
        } elseif ($user->role === UserRole::OPERATIONS) {
            $query->whereIn('status', [
                RequestStatus::APPROVED->value,
                RequestStatus::IN_PROGRESS->value,
                RequestStatus::COMPLETED->value,
            ]);
        } else {
            abort(403);
        }

        return ServiceRequestResource::collection(
            $query->paginate(15)
        );
    }

    /**
     * Show one request.
     */
    public function show(ServiceRequest $serviceRequest)
    {
        Gate::authorize('view', $serviceRequest);

        $serviceRequest->load([
            'employee',
            'assignedUser',
            'comments.user',
        ]);

        return new ServiceRequestResource($serviceRequest);
    }

    /**
     * Employee creates a draft request.
     */
    public function store(StoreServiceRequestRequest $request)
    {
        Gate::authorize('create', ServiceRequest::class);

        $serviceRequest = ServiceRequest::create([
            ...$request->validated(),
            'employee_id' => $request->user()->id,
            'status' => RequestStatus::DRAFT,
        ]);

        $serviceRequest->load(['employee', 'assignedUser']);

        return (new ServiceRequestResource($serviceRequest))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Employee edits their own draft or returned request.
     */
    public function update(
        UpdateServiceRequestRequest $request,
        ServiceRequest $serviceRequest
    ) {
        Gate::authorize('update', $serviceRequest);

        $serviceRequest->update($request->validated());

        $serviceRequest->load(['employee', 'assignedUser']);

        return new ServiceRequestResource($serviceRequest);
    }

    /**
     * Employee submits a draft or returned request.
     */
    public function submit(ServiceRequest $serviceRequest)
        {
            Gate::authorize('submit', $serviceRequest);

            $updatedRequest = $this->workflow->transition(
                $serviceRequest,
                request()->user(),
                RequestStatus::SUBMITTED
            );

            return new ServiceRequestResource($updatedRequest);
        }

    /**
     * Manager approves, rejects, or returns a request.
     */
    public function decide(
        DecideServiceRequestRequest $request,
        ServiceRequest $serviceRequest
    ) {
        Gate::authorize('decide', $serviceRequest);

        $data = $request->validated();

        $serviceRequest = $this->workflow->transition(
            $serviceRequest,
            $request->user(),
            RequestStatus::from($data['decision']),
            $data['comment'] ?? null
        );

        $serviceRequest->load(['employee', 'assignedUser']);

        return new ServiceRequestResource($serviceRequest);
    }

    /**
     * Operations assigns an approved request to an operations user.
     */
    public function assign(
        AssignServiceRequestRequest $request,
        ServiceRequest $serviceRequest
    ) {
        Gate::authorize('assign', $serviceRequest);

        $data = $request->validated();

        $assignee = User::findOrFail($data['assigned_to']);

        DB::transaction(function () use (
            $serviceRequest,
            $request,
            $assignee
        ) {
            $lockedRequest = ServiceRequest::query()
                ->lockForUpdate()
                ->findOrFail($serviceRequest->id);

            $lockedRequest->assigned_to = $assignee->id;
            $lockedRequest->save();

            RequestActivity::create([
                'service_request_id' => $lockedRequest->id,
                'user_id' => $request->user()->id,
                'action' => 'assigned',
                'from_status' => $lockedRequest->status->value,
                'to_status' => $lockedRequest->status->value,
                'comment' => 'Assigned to ' . $assignee->name,
            ]);
        });
        Notification::send(
            [$assignee],
            new RequestStatusChangedNotification(
                requestId: $serviceRequest->id,
                requestTitle: $serviceRequest->title,
                action: 'assigned',
                fromStatus: $serviceRequest->status->value,
                toStatus: $serviceRequest->status->value,
                actorName: $request->user()->name,
                message: "You have been assigned request #{$serviceRequest->id}."
            )
        );

        $serviceRequest->refresh()->load(['employee', 'assignedUser']);

        return new ServiceRequestResource($serviceRequest);
    }

    /**
     * Operations starts or completes work.
     */
    public function changeStatus(
        UpdateServiceRequestStatusRequest $request,
        ServiceRequest $serviceRequest
    ) {
        Gate::authorize('updateStatus', $serviceRequest);

        $data = $request->validated();

        $newStatus = RequestStatus::from($data['status']);

        if (
            $newStatus === RequestStatus::IN_PROGRESS &&
            $serviceRequest->assigned_to === null
        ) {
            return response()->json([
                'message' => 'Assign an operations owner before starting work.',
            ], 422);
        }

        $serviceRequest = $this->workflow->transition(
            $serviceRequest,
            $request->user(),
            $newStatus,
            $data['comment'] ?? null
        );

        $serviceRequest->load(['employee', 'assignedUser']);

        return new ServiceRequestResource($serviceRequest);
    }

    /**
     * Add a comment to a request.
     */
    public function comment(
        StoreRequestCommentRequest $request,
        ServiceRequest $serviceRequest
    ) {
        Gate::authorize('comment', $serviceRequest);

        $comment = $serviceRequest->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated()['body'],
        ]);

        $comment->load('user');

        return response()->json([
            'message' => 'Comment added successfully.',
            'data' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                ],
                'created_at' => $comment->created_at,
            ],
        ], 201);
    }
}