<?php
final class TrainingSlots
{
    public static function normalize(array $slots, string $timezone): array
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) { throw new InvalidArgumentException('TrainingInvalidTimezone'); }
        if (!$slots || count($slots) > 100) { throw new InvalidArgumentException('TrainingInvalidSlots'); }
        $zone = new DateTimeZone($timezone); $normalized = array();
        foreach ($slots as $slot) {
            if (!is_array($slot)) { throw new InvalidArgumentException('TrainingInvalidSlots'); }
            $times = array();
            foreach (array('start', 'end') as $key) {
                $raw = $slot[$key] ?? '';
                if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00[+-]\d{2}:\d{2}$/D', $raw)) { throw new InvalidArgumentException('TrainingInvalidSlots'); }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $raw);
                if (!$date || $date->format('Y-m-d\TH:i:sP') !== $raw || $zone->getOffset($date) !== $date->getOffset()) { throw new InvalidArgumentException('TrainingInvalidSlots'); }
                $times[$key] = $date->getTimestamp();
            }
            $minutes = ($times['end'] - $times['start']) / 60;
            if ($minutes < 1 || $minutes > 10080) { throw new InvalidArgumentException('TrainingInvalidSlots'); }
            $normalized[] = array('start' => $times['start'], 'end' => $times['end'], 'duration_minutes' => (int) $minutes);
        }
        usort($normalized, fn($a, $b) => $a['start'] <=> $b['start']);
        foreach ($normalized as $i => $slot) {
            if ($i > 0 && $slot['start'] < $normalized[$i - 1]['end']) { throw new InvalidArgumentException('TrainingSlotsOverlap'); }
        }
        return $normalized;
    }
}
