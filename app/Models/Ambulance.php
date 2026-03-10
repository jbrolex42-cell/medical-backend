<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ambulance extends Model
{
    protected $fillable = [
        'vehicle_number',
        'license_plate',
        'type',
        'status',
        'current_latitude',
        'current_longitude',
        'equipment_level',
        'paramedic_count',
        'last_location_update'
    ];

    protected $casts = [
        'current_latitude' => 'float',
        'current_longitude' => 'float',
        'last_location_update' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    const STATUS_AVAILABLE = 'available';
    const STATUS_EN_ROUTE = 'en_route';
    const STATUS_ON_SCENE = 'on_scene';
    const STATUS_TRANSPORTING = 'transporting';
    const STATUS_MAINTENANCE = 'maintenance';
    const STATUS_OFFLINE = 'offline';

    const TYPE_BLS = 'basic_life_support';
    const TYPE_ALS = 'advanced_life_support';
    const TYPE_SPECIALTY = 'specialty_care';

    public function emergencies(): HasMany
    {
        return $this->hasMany(Emergency::class, 'assigned_ambulance_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function updateLocation(float $lat, float $lng): void
    {
        $this->update([
            'current_latitude' => $lat,
            'current_longitude' => $lng,
            'last_location_update' => now()
        ]);
    }

    public function calculateDistanceTo(float $lat, float $lng): float
    {
        $earthRadius = 6371; // km

        $latDelta = deg2rad($lat - $this->current_latitude);
        $lngDelta = deg2rad($lng - $this->current_longitude);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($this->current_latitude)) * cos(deg2rad($lat)) *
            sin($lngDelta / 2) * sin($lngDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}