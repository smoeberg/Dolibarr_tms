<?php
/** Normalize attested attendance, not access or workflow. */
final class TrainingAttendanceRecord
{
    public static function normalize(array $input, $slot, string $timezone): array
    {
        $status = $input['status'] ?? '';
        if (!is_string($status) || !in_array($status, array('not_registered', 'present', 'absent', 'excused', 'late'), true)) {
            throw new InvalidArgumentException('TrainingInvalidAttendance');
        }
        $arrival = trim((string) ($input['arrival'] ?? ''));
        $departure = trim((string) ($input['departure'] ?? ''));
        $empty = array('status' => $status, 'arrival_utc' => null, 'departure_utc' => null, 'present_minutes' => null, 'late_minutes' => null);
        if (in_array($status, array('not_registered', 'absent', 'excused'), true)) {
            if ($arrival !== '' || $departure !== '') { throw new InvalidArgumentException('TrainingAbsentHasTimes'); }
            if ($status !== 'not_registered') { $empty['present_minutes'] = $empty['late_minutes'] = 0; }
            return $empty;
        }
        $start = (new DateTimeImmutable($slot->start_utc, new DateTimeZone('UTC')))->getTimestamp();
        $end = (new DateTimeImmutable($slot->end_utc, new DateTimeZone('UTC')))->getTimestamp();
        if ($arrival === '' && $departure === '' && $status === 'present') {
            // Explicit full-slot attestation; do not invent an observed arrival timestamp.
            $empty['present_minutes'] = (int) (($end - $start) / 60); $empty['late_minutes'] = 0;
            return $empty;
        }
        if ($arrival === '' || $departure === '') { throw new InvalidArgumentException('TrainingAttendanceTimesRequired'); }
        $zone = new DateTimeZone($timezone); $times = array();
        foreach (array($arrival, $departure) as $raw) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00[+-]\d{2}:\d{2}$/D', $raw)) { throw new InvalidArgumentException('TrainingInvalidAttendanceTimes'); }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $raw);
            if (!$d || $d->format('Y-m-d\TH:i:sP') !== $raw || $zone->getOffset($d) !== $d->getOffset()) { throw new InvalidArgumentException('TrainingInvalidAttendanceTimes'); }
            $times[] = $d->getTimestamp();
        }
        if ($times[0] < $start || $times[1] > $end || $times[0] >= $times[1]) { throw new InvalidArgumentException('TrainingInvalidAttendanceTimes'); }
        $late = (int) (($times[0] - $start) / 60);
        if ($status === 'late' && $late === 0) { throw new InvalidArgumentException('TrainingInvalidAttendanceTimes'); }
        return array('status' => $late > 0 ? 'late' : 'present', 'arrival_utc' => gmdate('Y-m-d H:i:s', $times[0]), 'departure_utc' => gmdate('Y-m-d H:i:s', $times[1]), 'present_minutes' => (int) (($times[1] - $times[0]) / 60), 'late_minutes' => $late);
    }
    public static function state($row): array
    {
        return array('status' => $row->status, 'arrival_utc' => $row->arrival_utc, 'departure_utc' => $row->departure_utc,
            'present_minutes' => $row->present_minutes === null ? null : (int) $row->present_minutes,
            'late_minutes' => $row->late_minutes === null ? null : (int) $row->late_minutes);
    }
}
