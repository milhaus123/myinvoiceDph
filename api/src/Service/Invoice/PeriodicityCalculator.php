<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Počítá next_run_date pro pravidelnou fakturu.
 *
 * Periodicity:
 *   monthly         → +1 měsíc
 *   quarterly       → +3 měsíce
 *   semi_annually   → +6 měsíců
 *   annually        → +12 měsíců
 *
 * Pravidlo dne v měsíci:
 *   end_of_month=1            → poslední den cílového měsíce (28/29/30/31 dynamicky)
 *   day_of_month=1..28       → konkrétní den (28 max kvůli únoru)
 *   day_of_month=NULL         → den se odvodí z aktuálního next_run_date
 */
final class PeriodicityCalculator
{
    public const FREQUENCIES = ['monthly', 'quarterly', 'semi_annually', 'annually'];

    public static function monthsFor(string $frequency): int
    {
        return match ($frequency) {
            'monthly'       => 1,
            'quarterly'     => 3,
            'semi_annually' => 6,
            'annually'      => 12,
            default => throw new \InvalidArgumentException("Unknown frequency: $frequency"),
        };
    }

    /**
     * Vrátí YYYY-MM-DD příštího spuštění, počítáno z $current data + $frequency posunu.
     */
    public static function nextRunDate(
        string $current,
        string $frequency,
        bool $endOfMonth,
        ?int $dayOfMonth,
    ): string {
        $months = self::monthsFor($frequency);
        $base = new \DateTimeImmutable($current);

        $target = $base
            ->modify('first day of this month')
            ->modify("+{$months} months");

        if ($endOfMonth) {
            return $target->modify('last day of this month')->format('Y-m-d');
        }

        $day = $dayOfMonth ?? (int) $base->format('j');
        $day = max(1, min(28, $day));

        return $target
            ->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)
            ->format('Y-m-d');
    }

    /**
     * Vrátí YYYY-MM-DD pro N-tou fakturu od anchor date.
     */
    public static function nthRunDate(
        string $anchor,
        string $frequency,
        bool $endOfMonth,
        ?int $dayOfMonth,
        int $n,
    ): string {
        if ($n < 0) throw new \InvalidArgumentException('n must be >= 0');
        $months = self::monthsFor($frequency) * $n;
        $base = new \DateTimeImmutable($anchor);

        $target = $base
            ->modify('first day of this month')
            ->modify(($months >= 0 ? '+' : '') . "{$months} months");

        if ($endOfMonth) {
            return $target->modify('last day of this month')->format('Y-m-d');
        }

        $day = $dayOfMonth ?? (int) $base->format('j');
        $day = max(1, min(28, $day));

        return $target
            ->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)
            ->format('Y-m-d');
    }

    /**
     * Vrací seznam nadcházejících termínů generování (pro next-runs endpoint).
     *
     * @return list<array{run_date: string, template_id: int, template_name: string}>
     */
    public static function upcomingRuns(array $templates, int $maxCount = 10): array
    {
        $runs = [];
        foreach ($templates as $tpl) {
            if ($tpl['status'] !== 'active') continue;

            $current = (string) $tpl['next_run_date'];
            $endDate = !empty($tpl['end_date']) ? (string) $tpl['end_date'] : null;

            for ($i = 0; $i < 24; $i++) { // max 24 future runs
                if ($endDate !== null && $current > $endDate) break;

                $runs[] = [
                    'run_date' => $current,
                    'template_id' => (int) $tpl['id'],
                    'template_name' => (string) $tpl['name'],
                ];

                $current = self::nextRunDate(
                    $current,
                    (string) $tpl['frequency'],
                    !empty($tpl['end_of_month']),
                    $tpl['day_of_month'] ?? null,
                );
            }
        }

        usort($runs, fn($a, $b) => $a['run_date'] <=> $b['run_date']);
        return array_slice($runs, 0, $maxCount);
    }
}
