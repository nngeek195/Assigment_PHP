<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

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

    private function createRequest(User $employee): ServiceRequest
    {
        return ServiceRequest::create([
            'employee_id' => $employee->id,
            'title' => 'Test service request',
            'description' => 'Testing request workflow.',
            'category' => 'IT Equipment',
            'status' => RequestStatus::DRAFT,
        ]);
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

        $request = $this->createRequest($employee);
        $request->update([
            'status' => RequestStatus::SUBMITTED,
        ]);

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

        $request = $this->createRequest($employee);
        $request->update([
            'status' => RequestStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($managerOne);

        $this->postJson("/api/requests/{$request->id}/decision", [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_rejected_request_cannot_be_submitted_again(): void
    {
        $employee = $this->createUser(UserRole::EMPLOYEE);

        $request = $this->createRequest($employee);
        $request->update([
            'status' => RequestStatus::REJECTED,
        ]);

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
}
