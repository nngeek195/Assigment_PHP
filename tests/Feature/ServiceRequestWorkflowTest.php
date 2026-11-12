<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\RequestStatusChangedNotification;

class ServiceRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;
    
public function test_submission_queues_notification_for_manager(): void
{
    Notification::fake();

    $manager = $this->createUser(UserRole::MANAGER);
    $employee = $this->createUser(
        UserRole::EMPLOYEE,
        $manager->id
    );

    $request = $this->createRequest($employee);

    Sanctum::actingAs($employee);

    $this->postJson("/api/requests/{$request->id}/submit")
        ->assertOk();

    Notification::assertSentTo(
        $manager,
        RequestStatusChangedNotification::class,
        function ($notification) use ($request) {
            return $notification->toStatus === 'submitted';
        }
    );
}

public function test_approval_queues_notification_for_operations(): void
{
    Notification::fake();

    $manager = $this->createUser(UserRole::MANAGER);
    $operations = $this->createUser(UserRole::OPERATIONS);

    $employee = $this->createUser(
        UserRole::EMPLOYEE,
        $manager->id
    );

    $request = $this->createRequest(
        $employee,
        RequestStatus::SUBMITTED
    );

    Sanctum::actingAs($manager);

    $this->postJson("/api/requests/{$request->id}/decision", [
        'decision' => 'approved',
    ])->assertOk();

    Notification::assertSentTo(
        $operations,
        RequestStatusChangedNotification::class,
        function ($notification) {
            return $notification->toStatus === 'approved';
        }
    );
}

public function test_assignment_queues_notification_for_assignee(): void
{
    Notification::fake();

    $employee = $this->createUser(UserRole::EMPLOYEE);
    $operations = $this->createUser(UserRole::OPERATIONS);

    $request = $this->createApprovedRequest($employee);

    Sanctum::actingAs($operations);

    $this->postJson("/api/requests/{$request->id}/assign", [
        'assigned_to' => $operations->id,
    ])->assertOk();

    Notification::assertSentTo(
        $operations,
        RequestStatusChangedNotification::class,
        function ($notification) {
            return $notification->action === 'assigned';
        }
    );
}

    private function createUser(
        UserRole $role,
        ?int $managerId = null
    ): User {
        return User::create([
            'name' => ucfirst($role->value) . ' User',
            'email' => uniqid() . '@quantix.test',
            'password' => 'Password@123',
            'role' => $role,
            'manager_id' => $managerId,
        ]);
    }

    private function createRequest(
        User $employee,
        RequestStatus $status = RequestStatus::DRAFT
    ): ServiceRequest {
        return ServiceRequest::create([
            'employee_id' => $employee->id,
            'title' => 'Test service request',
            'description' => 'Testing request workflow.',
            'category' => 'IT Equipment',
            'status' => $status,
        ]);
    }

    private function createApprovedRequest(User $employee): ServiceRequest
    {
        return $this->createRequest(
            $employee,
            RequestStatus::APPROVED
        );
    }

    public function test_employee_can_create_a_request(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/requests', [
            'title' => 'New laptop',
            'description' => 'I need a development laptop.',
            'category' => 'IT Equipment',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.employee_id', $employee->id);

        $this->assertDatabaseHas('service_requests', [
            'employee_id' => $employee->id,
            'title' => 'New laptop',
            'status' => 'draft',
        ]);
    }

    public function test_employee_can_submit_own_draft_request(): void
    {
        $manager = $this->createUser(UserRole::MANAGER);
        $employee = $this->createUser(
            UserRole::EMPLOYEE,
            $manager->id
        );

        $request = $this->createRequest($employee);

        Sanctum::actingAs($employee);

        $this->postJson("/api/requests/{$request->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'user_id' => $employee->id,
            'from_status' => 'draft',
            'to_status' => 'submitted',
        ]);
    }

    public function test_manager_can_approve_direct_report_request(): void
    {
        $manager = $this->createUser(UserRole::MANAGER);
        $employee = $this->createUser(
            UserRole::EMPLOYEE,
            $manager->id
        );

        $request = $this->createRequest(
            $employee,
            RequestStatus::SUBMITTED
        );

        Sanctum::actingAs($manager);

        $this->postJson("/api/requests/{$request->id}/decision", [
            'decision' => 'approved',
            'comment' => 'Approved for business use.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'user_id' => $manager->id,
            'from_status' => 'submitted',
            'to_status' => 'approved',
        ]);
    }

    public function test_manager_cannot_decide_request_from_another_manager(): void
    {
        $managerOne = $this->createUser(UserRole::MANAGER);
        $managerTwo = $this->createUser(UserRole::MANAGER);

        $employee = $this->createUser(
            UserRole::EMPLOYEE,
            $managerTwo->id
        );

        $request = $this->createRequest(
            $employee,
            RequestStatus::SUBMITTED
        );

        Sanctum::actingAs($managerOne);

        $this->postJson("/api/requests/{$request->id}/decision", [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_rejected_request_cannot_be_submitted_again(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);

        $request = $this->createRequest(
            $employee,
            RequestStatus::REJECTED
        );

        Sanctum::actingAs($employee);

        $this->postJson("/api/requests/{$request->id}/submit")
            ->assertUnprocessable();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => 'rejected',
        ]);
    }

    public function test_employee_cannot_view_another_employees_request(): void
    {
        $employeeOne = $this->createUser(UserRole::EMPLOYEE);
        $employeeTwo = $this->createUser(UserRole::EMPLOYEE);

        $request = $this->createRequest($employeeTwo);

        Sanctum::actingAs($employeeOne);

        $this->getJson("/api/requests/{$request->id}")
            ->assertForbidden();
    }

    public function test_operations_can_assign_approved_request(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $operations = $this->createUser(UserRole::OPERATIONS);

        $request = $this->createApprovedRequest($employee);

        Sanctum::actingAs($operations);

        $this->postJson("/api/requests/{$request->id}/assign", [
            'assigned_to' => $operations->id,
        ])->assertOk();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'assigned_to' => $operations->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'user_id' => $operations->id,
            'action' => 'assigned',
        ]);
    }

    public function test_employee_cannot_be_assigned_as_operations_owner(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $operations = $this->createUser(UserRole::OPERATIONS);

        $request = $this->createApprovedRequest($employee);

        Sanctum::actingAs($operations);

        $this->postJson("/api/requests/{$request->id}/assign", [
            'assigned_to' => $employee->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'assigned_to' => null,
        ]);
    }

    public function test_assigned_operations_user_can_start_work(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $operations = $this->createUser(UserRole::OPERATIONS);

        $request = $this->createApprovedRequest($employee);

        $request->update([
            'assigned_to' => $operations->id,
        ]);

        Sanctum::actingAs($operations);

        $this->postJson("/api/requests/{$request->id}/status", [
            'status' => 'in_progress',
            'comment' => 'Work started.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'user_id' => $operations->id,
            'from_status' => 'approved',
            'to_status' => 'in_progress',
        ]);
    }

    public function test_unassigned_operations_user_cannot_start_work(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $operationsOne = $this->createUser(UserRole::OPERATIONS);
        $operationsTwo = $this->createUser(UserRole::OPERATIONS);

        $request = $this->createApprovedRequest($employee);

        $request->update([
            'assigned_to' => $operationsTwo->id,
        ]);

        Sanctum::actingAs($operationsOne);

        $this->postJson("/api/requests/{$request->id}/status", [
            'status' => 'in_progress',
        ])->assertForbidden();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);
    }

    public function test_assigned_operations_user_can_complete_work(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $operations = $this->createUser(UserRole::OPERATIONS);

        $request = $this->createRequest(
            $employee,
            RequestStatus::IN_PROGRESS
        );

        $request->update([
            'assigned_to' => $operations->id,
        ]);

        Sanctum::actingAs($operations);

        $this->postJson("/api/requests/{$request->id}/status", [
            'status' => 'completed',
            'comment' => 'Work completed successfully.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($request->fresh()->completed_at);

        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'user_id' => $operations->id,
            'from_status' => 'in_progress',
            'to_status' => 'completed',
        ]);
    }

    public function test_operations_cannot_complete_approved_request_directly(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $operations = $this->createUser(UserRole::OPERATIONS);

        $request = $this->createApprovedRequest($employee);

        $request->update([
            'assigned_to' => $operations->id,
        ]);

        Sanctum::actingAs($operations);

        $this->postJson("/api/requests/{$request->id}/status", [
            'status' => 'completed',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);
    }

    public function test_completed_request_cannot_change_status_again(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $operations = $this->createUser(UserRole::OPERATIONS);

        $request = $this->createRequest(
            $employee,
            RequestStatus::COMPLETED
        );

        $request->update([
            'assigned_to' => $operations->id,
        ]);

        Sanctum::actingAs($operations);

        $this->postJson("/api/requests/{$request->id}/status", [
            'status' => 'in_progress',
        ])->assertForbidden();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => 'completed',
        ]);
    }

    public function test_activity_history_is_visible_to_request_owner(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);
        $request = $this->createRequest($employee);

        RequestActivity::create([
            'service_request_id' => $request->id,
            'user_id' => $employee->id,
            'action' => 'status_changed',
            'from_status' => 'draft',
            'to_status' => 'submitted',
            'comment' => 'Submitted for approval.',
        ]);

        Sanctum::actingAs($employee);

        $this->getJson("/api/requests/{$request->id}/activities")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'status_changed');
    }

    public function test_other_employee_cannot_view_activity_history(): void
    {
        $employeeOne = $this->createUser(UserRole::EMPLOYEE);
        $employeeTwo = $this->createUser(UserRole::EMPLOYEE);

        $request = $this->createRequest($employeeTwo);

        Sanctum::actingAs($employeeOne);

        $this->getJson("/api/requests/{$request->id}/activities")
            ->assertForbidden();
    }
}
