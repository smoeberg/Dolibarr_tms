<?php
/** Resolve minute-precision wall-clock input without PHP's implicit DST normalization. */
final class TrainingLocalTime
{
    public static function resolve(string $local, string $timezone, string $selectedOffset = ''): string {
        if ($local === '') { return ''; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $local)) { throw new InvalidArgumentException('TrainingInvalidAttendanceTimes'); }
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone('UTC'));
        if (!$naive || $naive->format('Y-m-d\TH:i') !== $local) { throw new InvalidArgumentException('TrainingInvalidAttendanceTimes'); }
        $zone = new DateTimeZone($timezone); $epoch = $naive->getTimestamp();
        $offsets = array($zone->getOffset($naive));
        foreach ($zone->getTransitions($epoch-172800, $epoch+172800) ?: array() as $transition) { $offsets[] = $transition['offset']; }
        $candidates = array();
        foreach (array_unique($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@'.($epoch-$offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d\TH:i') === $local && $candidate->getOffset() === $offset && $offset % 60 === 0) {
                $candidates[$candidate->format('P')] = $candidate->format('Y-m-d\TH:i:sP');
            }
        }
        if (!$candidates) { throw new InvalidArgumentException('TrainingAttendanceNonexistentTime'); }
        if ($selectedOffset !== '') {
            if (!isset($candidates[$selectedOffset])) { throw new InvalidArgumentException('TrainingInvalidAttendanceTimes'); }
            return $candidates[$selectedOffset];
        }
        if (count($candidates) !== 1) { throw new InvalidArgumentException('TrainingAttendanceAmbiguousTime'); }
        return reset($candidates);
    }
    public static function offsetChoices(string $startUtc, string $endUtc, string $timezone): array {
        $zone = new DateTimeZone($timezone);
        $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($endUtc, new DateTimeZone('UTC'));
        $choices = array($start->setTimezone($zone)->format('P'), $end->setTimezone($zone)->format('P'));
        foreach ($zone->getTransitions($start->getTimestamp()-172800, $end->getTimestamp()+172800) ?: array() as $transition) {
            $choices[] = (new DateTimeImmutable('@'.$transition['ts']))->setTimezone($zone)->format('P');
        }
        return array_values(array_unique($choices));
    }
}
