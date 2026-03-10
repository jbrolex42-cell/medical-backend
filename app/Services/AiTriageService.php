<?php

namespace App\Services;

class IntelligentTriageService
{
    /**
     * Main triage process
     */
    public function assessEmergency(array $data): array
    {
        $symptoms = $data['symptoms'] ?? [];
        $vitals = $data['vitals'] ?? [];

        $severityScore = $this->calculateSeverity($symptoms, $vitals);
        $priority = $this->determinePriority($severityScore);
        $ambulanceRequired = $this->needsAmbulance($severityScore);

        return [
            'severity_score' => $severityScore,
            'priority_level' => $priority,
            'ambulance_required' => $ambulanceRequired,
            'recommended_action' => $this->recommendedAction($priority)
        ];
    }

    /**
     * Calculate emergency severity score
     */
    private function calculateSeverity(array $symptoms, array $vitals): int
    {
        $score = 0;

        $criticalSymptoms = [
            'chest pain' => 40,
            'unconscious' => 50,
            'severe bleeding' => 45,
            'difficulty breathing' => 40,
            'stroke symptoms' => 50
        ];

        foreach ($symptoms as $symptom) {
            $symptom = strtolower($symptom);

            if (isset($criticalSymptoms[$symptom])) {
                $score += $criticalSymptoms[$symptom];
            } else {
                $score += 10;
            }
        }

        if (isset($vitals['heart_rate']) && $vitals['heart_rate'] > 120) {
            $score += 20;
        }

        if (isset($vitals['oxygen']) && $vitals['oxygen'] < 90) {
            $score += 30;
        }

        return $score;
    }

    /**
     * Determine triage priority
     */
    private function determinePriority(int $score): string
    {
        if ($score >= 80) {
            return 'critical';
        }

        if ($score >= 50) {
            return 'high';
        }

        if ($score >= 25) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Decide if ambulance is required
     */
    private function needsAmbulance(int $score): bool
    {
        return $score >= 50;
    }

    /**
     * Recommended action for the patient
     */
    private function recommendedAction(string $priority): string
    {
        return match ($priority) {
            'critical' => 'Dispatch ambulance immediately and alert nearest hospital.',
            'high' => 'Seek urgent medical care or ambulance dispatch.',
            'medium' => 'Visit nearest hospital for evaluation.',
            'low' => 'Monitor symptoms or schedule medical consultation.',
            default => 'Consult healthcare provider.'
        };
    }
}