<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Emergency extends Model
{
    protected $fillable = [
        'reported_by',
        'patient_name',
        'patient_phone',
        'description',
        'priority',
        'status',
        'latitude',
        'longitude',
        'address',
        'medical_notes',
        'assigned_ambulance_id',
        'assigned_hospital_id',
        'dispatched_at',
        'arrived_at',
        'completed_at'
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'dispatched_at' => 'datetime',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_DISPATCHED = 'dispatched';
    const STATUS_EN_ROUTE = 'en_route';
    const STATUS_ON_SCENE = 'on_scene';
    const STATUS_TRANSPORTING = 'transporting';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function ambulance(): BelongsTo
    {
        return $this->belongsTo(Ambulance::class, 'assigned_ambulance_id');
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'assigned_hospital_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_DISPATCHED,
            self::STATUS_EN_ROUTE,
            self::STATUS_ON_SCENE,
            self::STATUS_TRANSPORTING
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_DISPATCHED,
            self::STATUS_EN_ROUTE,
            self::STATUS_ON_SCENE,
            self::STATUS_TRANSPORTING
        ]);
    }
}