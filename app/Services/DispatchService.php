<?php

namespace App\Services;

use App\Models\Emergency;
use App\Models\Ambulance;
use App\Models\MotorcycleUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchService
{
    /**
     * Find and assign nearest available responder
     * Uses Uber-style proximity algorithm
     */
    public function assignNearestResponder(Emergency $emergency)
    {
        $patientLat = $emergency->latitude;
        $patientLng = $emergency->longitude;
        $triage = $emergency->triage_category;

        // Priority: Critical emergencies get nearest available regardless of type
        // Others get standard ambulance
        
        if ($triage === 'critical') {
            // Try motorcycle first for fastest response
            $motorcycle = $this->findNearestMotorcycle($patientLat, $patientLng);
            
            if ($motorcycle && $motorcycle->distance < 5) { // Within 5km
                return [
                    'motorcycle_id' => $motorcycle->id,
                    'ambulance_id' => null,
                    'eta_minutes' => ceil($motorcycle->distance / 0.8), // 48km/h avg
                    'responder_type' => 'motorcycle'
                ];
            }
        }

        // Find nearest ambulance
        $ambulance = $this->findNearestAmbulance($patientLat, $patientLng, $triage);

        if (!$ambulance) {
            Log::warning('No available ambulances for emergency', ['emergency_id' => $emergency->id]);
            return null;
        }

        // Update ambulance status
        Ambulance::where('id', $ambulance->id)->update(['status' => 'busy']);

        return [
            'ambulance_id' => $ambulance->id,
            'motorcycle_id' => null,
            'eta_minutes' => ceil($ambulance->distance / 0.5), // 30km/h avg in traffic
            'responder_type' => 'ambulance',
            'distance_km' => round($ambulance->distance, 2)
        ];
    }

    /**
     * Find nearest available ambulance using Haversine formula
     */
    private function findNearestAmbulance($lat, $lng, $triage)
    {
        $query = "
            SELECT 
                a.*,
                (6371 * acos(
                    cos(radians(?)) *
                    cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) *
                    sin(radians(latitude))
                )) AS distance
            FROM ambulances a
            WHERE a.status = 'available'
            AND a.latitude IS NOT NULL
            AND a.longitude IS NOT NULL
        ";

        // For critical cases, prioritize equipped ambulances
        if ($triage === 'critical') {
            $query .= " AND (a.vehicle_type = 'advanced' OR a.oxygen_capacity > 0)";
        }

        $query .= " ORDER BY distance LIMIT 1";

        $result = DB::select($query, [$lat, $lng, $lat]);

        return $result[0] ?? null;
    }

    /**
     * Find nearest motorcycle responder
     */
    private function findNearestMotorcycle($lat, $lng)
    {
        return DB::table('motorcycle_units')
            ->selectRaw("
                *,
                (6371 * acos(
                    cos(radians(?)) *
                    cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) *
                    sin(radians(latitude))
                )) AS distance
            ", [$lat, $lng, $lat])
            ->where('status', 'available')
            ->whereNotNull('latitude')
            ->orderBy('distance')
            ->first();
    }

    /**
     * Calculate optimal route considering traffic and road conditions
     */
    public function calculateOptimalRoute($fromLat, $fromLng, $toLat, $toLng)
    {
        // Integration with mapping API (Google Maps/Mapbox)
        // Returns turn-by-turn directions and ETA
        
        return [
            'distance_km' => $this->calculateDistance($fromLat, $fromLng, $toLat, $toLng),
            'duration_minutes' => null, // Would come from Maps API
            'polyline' => null, // Encoded route polyline
            'turns' => [] // Turn-by-turn instructions
        ];
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
}