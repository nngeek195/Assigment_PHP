<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,

            'status' => $this->status->value,

            'employee_id' => $this->employee_id,
            'assigned_to' => $this->assigned_to,

            'employee' => $this->whenLoaded(
                'employee',
                fn () => [
                    'id' => $this->employee->id,
                    'name' => $this->employee->name,
                    'email' => $this->employee->email,
                ]
            ),

            'assigned_user' => $this->whenLoaded(
                'assignedUser',
                fn () => $this->assignedUser ? [
                    'id' => $this->assignedUser->id,
                    'name' => $this->assignedUser->name,
                    'email' => $this->assignedUser->email,
                ] : null
            ),

            'comments' => $this->whenLoaded(
                'comments',
                fn () => $this->comments->map(fn ($comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'user' => [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                    ],
                    'created_at' => $comment->created_at,
                ])
            ),

            'submitted_at' => $this->submitted_at,
            'completed_at' => $this->completed_at,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
