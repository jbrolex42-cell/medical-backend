<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Emergency;
use App\Models\Ambulance;
use App\Models\MedicalRecord;
use App\Models\DispatchLog;
use App\Models\GpsTracking;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EMTController extends Controller
{
    /**
     * Get current dispatch queue for EMT
     */
    public function getDispatchQueue(Request $request)
    {
        $emt = Auth::user()->emtResponder;
        
        if (!$emt) {
            return response()->json(['message' => 'Not authorized as EMT'], 403);
        }

        // Get pending emergencies near EMT location
        $emergencies = Emergency::where('status', 'pending')
            ->whereNull('assigned_ambulance_id')
            ->with('patient')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($emergency) use ($emt) {
                $distance = $this->calculateDistance(
                    $emt->current_latitude,
                    $emt->current_longitude,
                    $emergency->latitude,
                    $emergency->longitude
                );
                $emergency->distance_km = round($distance, 2);
                $emergency->estimated_eta = ceil($distance / 0.5); // 30km/h
                return $emergency;
            })
            ->sortBy('distance_km')
            ->values();

        return response()->json([
            'available_emergencies' => $emergencies,
            'emt_status' => $emt->is_available ? 'available' : 'busy',
            'current_location' => [
                'lat' => $emt->current_latitude,
                'lng' => $emt->current_longitude
            ]
        ]);
    }

    /**
     * Accept emergency dispatch
     */
    public function acceptDispatch(Request $request)
    {
        $request->validate([
            'emergency_id' => 'required|exists:emergencies,id'
        ]);

        $emt = Auth::user()->emtResponder;
        $emergency = Emergency::findOrFail($request->emergency_id);

        if ($emergency->status !== 'pending') {
            return response()->json(['message' => 'Emergency already assigned'], 400);
        }

        // Get ambulance assigned to this EMT
        $ambulance = Ambulance::where('driver_id', $emt->id)
            ->orWhere('paramedic_id', $emt->id)
            ->where('status', 'available')
            ->first();

        if (!$ambulance) {
            return response()->json(['message' => 'No available ambulance assigned to you'], 400);
        }

        DB::transaction(function () use ($emergency, $ambulance, $emt) {
            // Update emergency
            $emergency->update([
                'assigned_ambulance_id' => $ambulance->id,
                'status' => 'dispatched',
                'eta_minutes' => $this->calculateETA($emergency, $ambulance)
            ]);

            // Update ambulance status
            $ambulance->update(['status' => 'busy']);

            // Update EMT status
            $emt->update(['is_available' => false]);

            // Create dispatch log
            DispatchLog::create([
                'emergency_id' => $emergency->id,
                'ambulance_id' => $ambulance->id,
                'dispatched_at' => now(),
                'accepted_at' => now()
            ]);

            // Create notification for patient
            Notification::create([
                'user_id' => $emergency->patient_id,
                'type' => 'ambulance_assigned',
                'title' => 'Ambulance Assigned',
                'message' => "Ambulance {$ambulance->vehicle_number} is on the way",
                'data' => ['ambulance_id' => $ambulance->id, 'eta' => $emergency->eta_minutes]
            ]);
        });

        return response()->json([
            'message' => 'Dispatch accepted',
            'emergency' => $emergency->load('patient', 'ambulance')
        ]);
    }

    /**
     * Update emergency status (en_route, arrived_scene, etc.)
     */
    public function updateStatus(Request $request)
    {
        $request->validate([
            'emergency_id' => 'required|exists:emergencies,id',
            'status' => 'required|in:en_route,arrived_scene,departed_scene,arrived_hospital,completed',
            'notes' => 'nullable|string',
            'vitals' => 'nullable|array',
            'treatment_notes' => 'nullable|string'
        ]);

        $emergency = Emergency::findOrFail($request->emergency_id);
        $emt = Auth::user()->emtResponder;

        // Verify EMT is assigned to this emergency
        if ($emergency->ambulance->driver_id !== $emt->id && 
            $emergency->ambulance->paramedic_id !== $emt->id) {
            return response()->json(['message' => 'Not authorized for this emergency'], 403);
        }

        $oldStatus = $emergency->status;
        $newStatus = $request->status;

        DB::transaction(function () use ($emergency, $newStatus, $request, $emt) {
            // Update emergency status
            $emergency->update([
                'status' => $newStatus,
                'updated_at' => now()
            ]);

            // Update dispatch log timestamps
            $dispatchLog = DispatchLog::where('emergency_id', $emergency->id)->first();
            
            $timestampFields = [
                'en_route' => 'accepted_at',
                'arrived_scene' => 'arrived_scene_at',
                'departed_scene' => 'departed_scene_at',
                'arrived_hospital' => 'arrived_hospital_at',
                'completed' => 'completed_at'
            ];

            if (isset($timestampFields[$newStatus])) {
                $field = $timestampFields[$newStatus];
                $dispatchLog->update([$field => now()]);
            }

            // Calculate response time on arrival
            if ($newStatus === 'arrived_scene') {
                $responseTime = $dispatchLog->dispatched_at->diffInSeconds(now());
                $dispatchLog->update(['response_time_seconds' => $responseTime]);
            }

            // Create/update medical record if vitals provided
            if ($request->has('vitals')) {
                MedicalRecord::updateOrCreate(
                    ['emergency_id' => $emergency->id],
                    [
                        'patient_id' => $emergency->patient_id,
                        'emt_id' => $emt->id,
                        'vitals' => $request->vitals,
                        'treatment_notes' => $request->treatment_notes,
                        'updated_at' => now()
                    ]
                );
            }

            // If completed, free up ambulance and EMT
            if ($newStatus === 'completed') {
                $emergency->ambulance->update(['status' => 'available']);
                $emt->update(['is_available' => true]);
            }

            // Notify patient of status change
            $statusMessages = [
                'en_route' => 'Ambulance is on the way to you',
                'arrived_scene' => 'Ambulance has arrived at your location',
                'departed_scene' => 'Transporting to hospital',
                'arrived_hospital' => 'Arrived at hospital'
            ];

            if (isset($statusMessages[$newStatus])) {
                Notification::create([
                    'user_id' => $emergency->patient_id,
                    'type' => 'emergency_status',
                    'title' => 'Status Update',
                    'message' => $statusMessages[$newStatus],
                    'data' => ['status' => $newStatus, 'emergency_id' => $emergency->id]
                ]);
            }
        });

        return response()->json([
            'message' => 'Status updated',
            'emergency' => $emergency->fresh(),
            'response_time' => $dispatchLog->response_time_seconds ?? null
        ]);
    }

    /**
     * Update EMT location (called frequently from mobile app)
     */
    public function updateLocation(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'speed' => 'nullable|numeric',
            'heading' => 'nullable|numeric',
            'emergency_id' => 'nullable|exists:emergencies,id'
        ]);

        $emt = Auth::user()->emtResponder;

        // Update EMT location
        $emt->update([
            'current_latitude' => $request->latitude,
            'current_longitude' => $request->longitude,
            'last_location_update' => now()
        ]);

        // Log GPS tracking
        GpsTracking::create([
            'trackable_type' => 'emt_responder',
            'trackable_id' => $emt->id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'speed' => $request->speed,
            'heading' => $request->heading,
            'recorded_at' => now()
        ]);

        // If on active emergency, update ambulance location too
        if ($request->emergency_id) {
            $emergency = Emergency::find($request->emergency_id);
            if ($emergency && $emergency->ambulance) {
                $emergency->ambulance->update([
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude
                ]);

                // Broadcast to patient via WebSocket
                broadcast(new \App\Events\AmbulanceLocationUpdated([
                    'emergency_id' => $emergency->id,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'speed' => $request->speed,
                    'eta' => $this->calculateRemainingETA($emergency, $request->latitude, $request->longitude)
                ]))->toOthers();
            }
        }

        return response()->json(['message' => 'Location updated']);
    }

    /**
     * Get current active emergency for EMT
     */
    public function getCurrentEmergency(Request $request)
    {
        $emt = Auth::user()->emtResponder;

        $emergency = Emergency::whereIn('status', ['dispatched', 'en_route', 'arrived_scene', 'transporting'])
            ->whereHas('ambulance', function ($q) use ($emt) {
                $q->where('driver_id', $emt->id)
                  ->orWhere('paramedic_id', $emt->id);
            })
            ->with(['patient', 'patient.patient', 'destinationHospital', 'medicalRecords'])
            ->first();

        if (!$emergency) {
            return response()->json(['message' => 'No active emergency', 'emergency' => null]);
        }

        return response()->json([
            'emergency' => $emergency,
            'dispatch_log' => DispatchLog::where('emergency_id', $emergency->id)->first()
        ]);
    }

    /**
     * Upload medical record attachment (photo, document)
     */
    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'emergency_id' => 'required|exists:emergencies,id',
            'file' => 'required|file|max:10240', // 10MB max
            'type' => 'required|in:photo,document,audio'
        ]);

        $emergency = Emergency::findOrFail($request->emergency_id);
        
        // Verify authorization
        $emt = Auth::user()->emtResponder;
        if ($emergency->ambulance->driver_id !== $emt->id && 
            $emergency->ambulance->paramedic_id !== $emt->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $file = $request->file('file');
        $path = $file->store("emergencies/{$emergency->id}/{$request->type}", 'private');

        // Update medical record with attachment reference
        $record = MedicalRecord::firstOrCreate(
            ['emergency_id' => $emergency->id],
            ['patient_id' => $emergency->patient_id, 'emt_id' => $emt->id]
        );

        $attachments = $record->attachments ?? [];
        $attachments[] = [
            'type' => $request->type,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String()
        ];

        $record->update(['attachments' => $attachments]);

        return response()->json([
            'message' => 'File uploaded',
            'path' => $path
        ]);
    }

    /**
     * Get EMT performance stats
     */
    public function getStats(Request $request)
    {
        $emt = Auth::user()->emtResponder;

        $stats = [
            'total_emergencies' => DispatchLog::whereHas('emergency', function ($q) use ($emt) {
                $q->whereHas('ambulance', function ($aq) use ($emt) {
                    $aq->where('driver_id', $emt->id)
                       ->orWhere('paramedic_id', $emt->id);
                });
            })->count(),
            
            'avg_response_time' => DispatchLog::whereHas('emergency', function ($q) use ($emt) {
                $q->whereHas('ambulance', function ($aq) use ($emt) {
                    $aq->where('driver_id', $emt->id)
                       ->orWhere('paramedic_id', $emt->id);
                });
            })->whereNotNull('response_time_seconds')
              ->avg('response_time_seconds') / 60,
            
            'this_month' => DispatchLog::whereHas('emergency', function ($q) use ($emt) {
                $q->whereHas('ambulance', function ($aq) use ($emt) {
                    $aq->where('driver_id', $emt->id)
                       ->orWhere('paramedic_id', $emt->id);
                })->whereMonth('created_at', now()->month);
            })->count(),
            
            'rating' => 4.8 // Calculate from patient feedback
        ];

        return response()->json($stats);
    }

    // Helper methods
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

    private function calculateETA($emergency, $ambulance)
    {
        $distance = $this->calculateDistance(
            $ambulance->latitude,
            $ambulance->longitude,
            $emergency->latitude,
            $emergency->longitude
        );
        return ceil($distance / 0.5); // 30km/h average
    }

    private function calculateRemainingETA($emergency, $currentLat, $currentLng)
    {
        $destination = $emergency->status === 'en_route' 
            ? [$emergency->latitude, $emergency->longitude]
            : [$emergency->destinationHospital->latitude, $emergency->destinationHospital->longitude];

        $distance = $this->calculateDistance($currentLat, $currentLng, $destination[0], $destination[1]);
        return ceil($distance / 0.5);
    }
}