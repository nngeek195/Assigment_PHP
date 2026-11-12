<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'assigned_to',
        'title',
        'description',
        'category',
        'status',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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
