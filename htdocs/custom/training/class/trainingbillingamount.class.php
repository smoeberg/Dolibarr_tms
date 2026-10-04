<?php
/** Exact, non-negative base-currency amounts with eight decimal places. */
final class TrainingBillingAmount
{
    public static function units(string $value): int {
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,8}))?$/D', $value, $m)) { throw new InvalidArgumentException('TrainingInvalidBillingAmount'); }
        return (int) $m[1] * 100000000 + (int) str_pad($m[2] ?? '', 8, '0');
    }
    public static function text(int $units): string {
        if ($units < 0) { throw new InvalidArgumentException('TrainingInvalidBillingAmount'); }
        return intdiv($units, 100000000).'.'.str_pad((string) ($units % 100000000), 8, '0', STR_PAD_LEFT);
    }
    private static function portion(int $amount, int $weight, int $totalWeight): int {
        // Split before multiplying: avoids overflow and binary floating-point arithmetic.
        $remainder = ($amount % $totalWeight) * $weight;
        return intdiv($amount, $totalWeight) * $weight + intdiv($remainder, $totalWeight) + (($remainder % $totalWeight) * 2 >= $totalWeight ? 1 : 0);
    }
    public static function distribute(string $ht, string $ttc, array $weights): array {
        if (!$weights || count($weights) > 1000) { throw new InvalidArgumentException('TrainingInvalidBillingShares'); }
        $total = 0;
        foreach ($weights as $id => $weight) {
            if (!is_int($id) || $id < 1 || !is_int($weight) || $weight < 1 || $weight > 10000) { throw new InvalidArgumentException('TrainingInvalidBillingShares'); }
            $total += $weight;
        }
        ksort($weights, SORT_NUMERIC);
        $amounts = array('amount_ht' => self::units($ht), 'amount_ttc' => self::units($ttc));
        $cumulative = 0; $previous = array('amount_ht' => 0, 'amount_ttc' => 0); $rows = array();
        foreach ($weights as $id => $weight) {
            $cumulative += $weight; $row = array('enrollment_id' => $id, 'weight' => $weight);
            foreach ($amounts as $field => $amount) {
                $next = self::portion($amount, $cumulative, $total);
                $row[$field] = self::text($next - $previous[$field]); $previous[$field] = $next;
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
