<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Emergency;
use App\Models\user;

class ReportController extends ReportController
{

    public function index()
    {
        return view('report.index', [
            'title' => 'Report Emergency'
        ]);
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'phone' => 'required|string|max:20',
            'emergency_type' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric'
        ]);

        try {

            DB::beginTransaction();

            $trackingId = $this->generateTrackingId();

            $emergency = Emergency::create([
                'tracking_id' => $trackingId,
                'reporter_name' => $validated['name'] ?? 'Anonymous',
                'reporter_phone' => $validated['phone'],
                'emergency_type' => $validated['emergency_type'],
                'description' => $validated['description'] ?? null,
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'status' => 'pending'
            ]);

            DB::commit();

            return redirect("/track/{$trackingId}")
                ->with('success', 'Emergency reported successfully');

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with('error', 'Failed to report emergency');
        }
    }

    /**
     * Track emergency status
     */
    public function track($trackingId)
    {
        $emergency = Emergency::where('tracking_id', $trackingId)->first();

        if (!$emergency) {
            return redirect('/')
                ->with('error', 'Tracking ID not found');
        }

        return view('report.track', [
            'title' => 'Track Emergency',
            'emergency' => $emergency
        ]);
    }

    /**
     * Generate unique tracking ID
     */
    private function generateTrackingId()
    {
        do {
            $trackingId = 'EMS-' . strtoupper(Str::random(8));
        } while (Emergency::where('tracking_id', $trackingId)->exists());

        return $trackingId;
    }
}