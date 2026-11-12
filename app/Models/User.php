<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'manager_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    // Manager of this employee
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    // Employees reporting to this manager
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    // Requests created by this employee
    public function createdRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'employee_id');
    }

    // Requests assigned to this operations user
    public function assignedRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(RequestComment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(RequestActivity::class);
    }
}
