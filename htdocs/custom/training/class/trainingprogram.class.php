<?php
/** Pure validation; no ERP identities or prices are duplicated here. */
final class TrainingProgram
{
    public static function normalize(array $input): array
    {
        $goals = trim((string) ($input['goals'] ?? ''));
        $prerequisites = trim((string) ($input['prerequisites'] ?? ''));
        $audience = trim((string) ($input['target_audience'] ?? ''));
        foreach (array($goals, $prerequisites, $audience) as $text) {
            if (strlen($text) > 16000) {
                throw new InvalidArgumentException('TrainingTextTooLong');
            }
        }
        $modality = (string) ($input['modality'] ?? 'physical');
        if (!in_array($modality, array('physical', 'online', 'blended'), true)) {
            throw new InvalidArgumentException('TrainingInvalidModality');
        }
        $units = $input['units'] ?? array();
        if (!is_array($units) || count($units) < 1 || count($units) > 100) {
            throw new InvalidArgumentException('TrainingInvalidUnits');
        }
        $normalized = array();
        $total = 0;
        foreach ($units as $unit) {
            if (!is_array($unit)) {
                throw new InvalidArgumentException('TrainingInvalidUnits');
            }
            $label = trim((string) ($unit['label'] ?? ''));
            $minutes = $unit['duration_minutes'] ?? null;
            // Reject fractional values, exponent notation and silent coercion.
            if ($label === '' || strlen($label) > 255 || !is_int($minutes) || $minutes < 1 || $minutes > 10080) {
                throw new InvalidArgumentException('TrainingInvalidUnits');
            }
            $normalized[] = array('position' => count($normalized) + 1, 'label' => $label, 'duration_minutes' => $minutes);
            $total += $minutes;
        }
        return array('goals' => $goals, 'prerequisites' => $prerequisites, 'target_audience' => $audience, 'modality' => $modality, 'duration_minutes' => $total, 'units' => $normalized);
    }

    public static function requirePublishable(array $program): void
    {
        if (trim($program['goals']) === '' || trim($program['target_audience']) === '') {
            throw new InvalidArgumentException('TrainingPublishNeedsGoalsAndAudience');
        }
    }
}
