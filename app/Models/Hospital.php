<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hospital extends Model
{
    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'phone',
        'emergency_capacity',
        'current_occupancy',
        'trauma_level',
        'specialties',
        'is_active',
        'average_wait_time'
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'specialties' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    const TRAUMA_LEVEL_I = 1;
    const TRAUMA_LEVEL_II = 2;
    const TRAUMA_LEVEL_III = 3;
    const TRAUMA_LEVEL_IV = 4;
    const TRAUMA_LEVEL_V = 5;

    public function emergencies(): HasMany
    {
        return $this->hasMany(Emergency::class, 'assigned_hospital_id');
    }

    public function availableBeds(): int
    {
        return $this->emergency_capacity - $this->current_occupancy;
    }

    public function hasCapacity(): bool
    {
        return $this->availableBeds() > 0 && $this->is_active;
    }

    public function calculateDistanceTo(float $lat, float $lng): float
    {
        $earthRadius = 6371;

        $latDelta = deg2rad($lat - $this->latitude);
        $lngDelta = deg2rad($lng - $this->longitude);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) *
            sin($lngDelta / 2) * sin($lngDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function updateOccupancy(int $change): void
    {
        $this->increment('current_occupancy', $change);
    }
}