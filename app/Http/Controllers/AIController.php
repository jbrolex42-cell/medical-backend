<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    /**
     * AI-powered emergency triage
     * Analyzes symptoms and assigns priority
     */
    public function analyzeSymptoms(Request $request)
    {
        $validated = $request->validate([
            'symptoms' => 'required|string',
            'age' => 'nullable|integer',
            'gender' => 'nullable|in:male,female,other',
            'medical_history' => 'nullable|string'
        ]);

        $symptoms = strtolower($validated['symptoms']);
        
        // Rule-based triage system (can be replaced with ML model)
        $triage = $this->ruleBasedTriage($symptoms);
        
        // Enhanced analysis with external AI service if available
        $aiAnalysis = $this->getAIAnalysis($validated);

        return response()->json([
            'triage_category' => $triage['category'],
            'priority_score' => $triage['score'],
            'recommended_response' => $triage['response'],
            'possible_conditions' => $triage['conditions'],
            'ai_confidence' => $aiAnalysis['confidence'] ?? 0.85,
            'advice' => $triage['advice']
        ]);
    }

    /**
     * Process voice emergency using speech-to-text
     */
    public function processVoiceEmergency(Request $request)
    {
        $validated = $request->validate([
            'audio_data' => 'required|string', // Base64 encoded audio
            'language' => 'nullable|string|default:en'
        ]);

        // Decode and process audio
        $audioBinary = base64_decode($validated['audio_data']);
        
        // Send to speech recognition service
        $transcription = $this->transcribeAudio($audioBinary, $validated['language']);
        
        if (!$transcription) {
            return response()->json(['error' => 'Could not process audio'], 422);
        }

        // Analyze transcribed text
        $triage = $this->ruleBasedTriage(strtolower($transcription));

        return response()->json([
            'transcription' => $transcription,
            'triage' => $triage,
            'emergency_detected' => $triage['score'] >= 7
        ]);
    }

    private function ruleBasedTriage($symptoms)
    {
        $criticalKeywords = [
            'not breathing', 'unconscious', 'no pulse', 'severe bleeding',
            'chest pain', 'heart attack', 'stroke', 'seizure', 'choking',
            'drowning', 'electrocution', 'severe burn', 'head injury',
            'spinal injury', 'poisoning', 'allergic reaction', 'anaphylaxis'
        ];

        $urgentKeywords = [
            'broken bone', 'fracture', 'deep cut', 'moderate bleeding',
            'asthma attack', 'diabetic emergency', 'severe pain', 'high fever',
            'dehydration', 'pregnancy emergency', 'labor'
        ];

        $moderateKeywords = [
            'sprain', 'minor cut', 'nosebleed', 'moderate pain',
            'vomiting', 'diarrhea', 'migraine', 'back pain'
        ];

        $score = 0;
        $matchedConditions = [];

        foreach ($criticalKeywords as $keyword) {
            if (str_contains($symptoms, $keyword)) {
                $score += 10;
                $matchedConditions[] = $keyword;
            }
        }

        foreach ($urgentKeywords as $keyword) {
            if (str_contains($symptoms, $keyword)) {
                $score += 6;
                $matchedConditions[] = $keyword;
            }
        }

        foreach ($moderateKeywords as $keyword) {
            if (str_contains($symptoms, $keyword)) {
                $score += 3;
                $matchedConditions[] = $keyword;
            }
        }

        // Determine category
        if ($score >= 10) {
            $category = 'critical';
            $response = 'Immediate dispatch of advanced life support ambulance';
            $advice = 'Stay calm. If CPR is needed, start immediately. Clear airway.';
        } elseif ($score >= 6) {
            $category = 'urgent';
            $response = 'Urgent ambulance dispatch within 15 minutes';
            $advice = 'Keep patient comfortable and warm. Do not move if spinal injury suspected.';
        } elseif ($score >= 3) {
            $category = 'moderate';
            $response = 'Standard ambulance dispatch within 30 minutes';
            $advice = 'Apply first aid if trained. Monitor vital signs.';
        } else {
            $category = 'minor';
            $response = 'Non-urgent transport or self-care advised';
            $advice = 'Consider visiting clinic if symptoms persist. Rest and hydrate.';
        }

        return [
            'category' => $category,
            'score' => min($score, 10),
            'response' => $response,
            'conditions' => array_unique($matchedConditions),
            'advice' => $advice
        ];
    }

    private function getAIAnalysis($data)
    {
        // Integration with OpenAI or custom ML model
        try {
            // $response = Http::withHeaders([
            //     'Authorization' => 'Bearer ' . config('services.openai.key')
            // ])->post('https://api.openai.com/v1/chat/completions', [
            //     'model' => 'gpt-4',
            //     'messages' => [
            //         ['role' => 'system', 'content' => 'You are a medical triage assistant.'],
            //         ['role' => 'user', 'content' => "Symptoms: {$data['symptoms']}"]
            //     ]
            // ]);
            
            return ['confidence' => 0.9, 'analysis' => 'AI analysis placeholder'];
        } catch (\Exception $e) {
            Log::error('AI Analysis failed: ' . $e->getMessage());
            return ['confidence' => 0, 'analysis' => null];
        }
    }

    private function transcribeAudio($audioBinary, $language)
    {
        // Integration with Google Speech-to-Text or similar
        // Return transcribed text
        return "Sample transcribed text from audio";
    }
}