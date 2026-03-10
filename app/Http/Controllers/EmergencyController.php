<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Emergency;
use App\Models\EmergencyType;
use App\Services\AiTriageService;
use App\Services\DispatchService;
use Illuminate\Support\Facades\Auth;

class EmergencyController extends AuthController
{
    protected AiTriageService $aiTriageService;
    protected DispatchService $dispatchService;

    public function __construct(
        AiTriageService $aiTriageService,
        DispatchService $dispatchService
    ) {
        $this->aiTriageService = $aiTriageService;
        $this->dispatchService = $dispatchService;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'emergency_type_id' => 'required|exists:emergency_types,id',
            'category' => 'required|string',
            'severity' => 'required|in:critical,urgent,moderate',
            'symptoms' => 'required|array',
            'vital_signs' => 'nullable|array',
            'patient_info' => 'nullable|array',
            'description' => 'nullable|string',
            'estimated_age' => 'nullable|integer',
            'special_considerations' => 'nullable|array'
        ]);

        $emergencyType = Emergency::findOrFail($validated['emergency_type_id']);

        $aiAnalysis = $this->aiTriageService->analyze([
            'emergency_type' => $emergencyType,
            'symptoms' => $validated['symptoms'],
            'vital_signs' => $validated['vital_signs'] ?? [],
            'patient_age' => $validated['estimated_age'] ?? null
        ]);

        // Ensure AI can only increase severity
        $finalSeverity = $validated['severity'];

        if (!empty($aiAnalysis['suggested_severity'])) {
            $priorityMap = [
                'moderate' => 1,
                'urgent' => 2,
                'critical' => 3
            ];

            if (
                $priorityMap[$aiAnalysis['suggested_severity']] >
                $priorityMap[$validated['severity']]
            ) {
                $finalSeverity = $aiAnalysis['suggested_severity'];
            }
        }


        $emergency = Emergency::create([
            'patient_id' => Auth::id(),
            'emergency_type_id' => $validated['emergency_type_id'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'triage_category' => $finalSeverity,
            'status' => 'pending',
            'selected_symptoms' => $validated['symptoms'],
            'vital_signs' => $validated['vital_signs'] ?? null,
            'patient_info' => $validated['patient_info'] ?? null,
            'description' => $validated['description'] ?? null,
            'ai_confidence' => $aiAnalysis['confidence'] ?? null,
            'ai_recommendations' => $aiAnalysis['recommendations'] ?? null
        ]);

        $dispatchResult = null;

        try {
            $dispatchResult = $this->dispatchService->DispatchService($emergency, [
                'required_equipment' => $emergencyType->required_equipment,
                'specialization_needed' => $emergencyType->dispatch_instructions
            ]);
        } catch (\Exception $e) {
            $dispatchResult = [
                'status' => 'dispatch_failed',
                'message' => $e->getMessage()
            ];
        }


        return response()->json([
            'emergency' => $emergency->load('type'),
            'dispatch' => $dispatchResult,
            'ai_analysis' => $aiAnalysis,
            'message' => 'Emergency created successfully'
        ], 201);
    }
}