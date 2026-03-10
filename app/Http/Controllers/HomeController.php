<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hospital;
use App\Models\HospitalBed;
use App\Models\Emergency;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HospitalController extends Controller
{
    /**
     * Get nearby hospitals with availability
     */
    public function getNearby(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'radius' => 'nullable|numeric|default:50', // km
            'type' => 'nullable|in:all,trauma,maternity,general'
        ]);

        $query = Hospital::selectRaw("
            *,
            (6371 * acos(
                cos(radians(?)) *
                cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) *
                sin(radians(latitude))
            )) AS distance
        ", [$request->lat, $request->lng, $request->lat])
        ->where('is_active', true)
        ->having('distance', '<=', $request->radius)
        ->orderBy('distance');

        if ($request->type && $request->type !== 'all') {
            $query->where("{$request->type}_capacity", '>', 0);
        }

        $hospitals = $query->get()->map(function ($hospital) {
            $hospital->available_beds = $hospital->beds()->where('status', 'available')->count();
            $hospital->total_beds = $hospital->beds()->count();
            $hospital->occupancy_rate = $hospital->total_beds > 0 
                ? round((($hospital->total_beds - $hospital->available_beds) / $hospital->total_beds) * 100, 1)
                : 0;
            return $hospital;
        });

        return response()->json($hospitals);
    }

    /**
     * Get detailed bed availability for a hospital
     */
    public function getBedAvailability($id)
    {
        $hospital = Hospital::findOrFail($id);

        $bedsByType = $hospital->beds()
            ->select('bed_type', 'status', DB::raw('count(*) as count'))
            ->groupBy('bed_type', 'status')
            ->get()
            ->groupBy('bed_type');

        $availability = [
            'icu' => ['total' => 0, 'available' => 0, 'occupied' => 0],
            'trauma' => ['total' => 0, 'available' => 0, 'occupied' => 0],
            'maternity' => ['total' => 0, 'available' => 0, 'occupied' => 0],
            'general' => ['total' => 0, 'available' => 0, 'occupied' => 0],
            'emergency' => ['total' => 0, 'available' => 0, 'occupied' => 0],
        ];

        foreach ($bedsByType as $type => $statuses) {
            foreach ($statuses as $status) {
                $availability[$type]['total'] += $status->count;
                if ($status->status === 'available') {
                    $availability[$type]['available'] = $status->count;
                } else {
                    $availability[$type]['occupied'] += $status->count;
                }
            }
        }

        // Get incoming patients
        $incoming = Emergency::where('destination_hospital_id', $id)
            ->whereIn('status', ['transporting', 'en_route'])
            ->with('patient')
            ->get();

        return response()->json([
            'hospital' => $hospital,
            'availability' => $availability,
            'incoming_patients' => $incoming,
            'last_updated' => now()->toIso8601String()
        ]);
    }

    /**
     * Reserve a bed for incoming patient
     */
    public function reserveBed(Request $request, $id)
    {
        $request->validate([
            'emergency_id' => 'required|exists:emergencies,id',
            'bed_type' => 'required|in:icu,trauma,maternity,general,emergency'
        ]);

        $hospital = Hospital::findOrFail($id);
        $emergency = Emergency::findOrFail($request->emergency_id);

        // Find available bed
        $bed = $hospital->beds()
            ->where('bed_type', $request->bed_type)
            ->where('status', 'available')
            ->first();

        if (!$bed) {
            return response()->json([
                'message' => 'No available beds of this type',
                'alternatives' => $this->suggestAlternatives($hospital, $request->bed_type)
            ], 400);
        }

        DB::transaction(function () use ($bed, $emergency, $hospital) {
            $bed->update([
                'status' => 'reserved',
                'patient_id' => $emergency->patient_id,
                'emergency_id' => $emergency->id,
                'reserved_until' => now()->addHours(2),
                'updated_at' => now()
            ]);

            $emergency->update([
                'destination_hospital_id' => $hospital->id,
                'reserved_bed_id' => $bed->id
            ]);

            // Notify hospital staff
            Notification::create([
                'user_id' => $hospital->user_id,
                'type' => 'bed_reserved',
                'title' => 'Bed Reserved',
                'message' => "Bed {$bed->bed_number} reserved for incoming patient",
                'data' => [
                    'emergency_id' => $emergency->id,
                    'bed_id' => $bed->id,
                    'eta' => $emergency->eta_minutes
                ]
            ]);
        });

        return response()->json([
            'message' => 'Bed reserved successfully',
            'bed' => $bed,
            'reserved_until' => $bed->reserved_until
        ]);
    }

public function updateBedStatus(Request $request)
{
    $request->validate([
        'bed_id' => 'required|exists:hospital_beds,id',
        'status' => 'required|in:available,occupied,reserved,maintenance,cleaning',
        'patient_id' => 'nullable|exists:users,id',
        'notes' => 'nullable|string'
    ]);

    $bed = HospitalBed::findOrFail($request->bed_id);

    // Verify user belongs to this hospital
    $hospital = Hospital::where('user_id', Auth::id())->first();

    if (!$hospital || $bed->hospital_id !== $hospital->id) {
        return response()->json([
            'message' => 'Unauthorized action'
        ], 403);
    }

    DB::transaction(function () use ($bed, $request) {

        $bed->update([
            'status' => $request->status,
            'patient_id' => $request->patient_id,
            'notes' => $request->notes,
            'updated_at' => now()
        ]);

        // If bed becomes available, clear reservation
        if ($request->status === 'available') {
            $bed->update([
                'patient_id' => null,
                'emergency_id' => null,
                'reserved_until' => null
            ]);
        }
    });

    return response()->json([
        'message' => 'Bed status updated successfully',
        'bed' => $bed
    ]);
}
}