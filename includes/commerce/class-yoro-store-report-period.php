<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class YoRo_Store_Report_Period
{
    public const Windows = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_week', 'last_week', 'week', 'this_month', 'last_month', 'month', 'this_quarter', 'last_quarter', 'quarter', 'this_year', 'last_year', 'year', 'custom'];

    public const Comparisons = ['previous_period', 'previous_year', 'custom', 'none'];

    public const Fields = ['window', 'date_basis', 'anchor_date', 'date_from', 'date_to', 'comparison', 'comparison_from', 'comparison_to'];

    public static function parameters(array $params): array
    {
        if (array_diff(array_keys($params), self::Fields) || ! in_array(array_key_exists('window', $params) ? $params['window'] : 'today', self::Windows, true)
            || ! in_array(array_key_exists('date_basis', $params) ? $params['date_basis'] : 'created', ['created', 'paid'], true)
            || ! in_array(array_key_exists('comparison', $params) ? $params['comparison'] : 'previous_period', self::Comparisons, true)) {
            throw new \InvalidArgumentException('Unsupported reporting scope.');
        }
        $params['window'] = $params['window'] ?? 'today';
        $anchor = in_array($params['window'], ['week', 'month', 'quarter', 'year'], true);
        $custom = $params['window'] === 'custom';
        $comparison = ($params['comparison'] ?? '') === 'custom';
        foreach (['anchor_date' => $anchor, 'date_from' => $custom, 'date_to' => $custom, 'comparison_from' => $comparison, 'comparison_to' => $comparison] as $field => $required) {
            if ($required !== array_key_exists($field, $params)) {
                throw new \InvalidArgumentException('Select only the dates required by this reporting scope.');
            }
            if ($required) {
                self::date($params[$field], new \DateTimeZone('UTC'));
            }
        }
        foreach ($custom ? [['date_from', 'date_to']] : [] as [$from,$to]) {
            self::bounds(self::date($params[$from], new \DateTimeZone('UTC')), self::date($params[$to], new \DateTimeZone('UTC')));
        }
        if ($comparison) {
            self::bounds(self::date($params['comparison_from'], new \DateTimeZone('UTC')), self::date($params['comparison_to'], new \DateTimeZone('UTC')));
        }

        return $params;
    }

    private static function date($value, \DateTimeZone $tz): \DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D', $value)) {
            throw new \InvalidArgumentException('Use a valid YYYY-MM-DD date.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Use a valid calendar date.');
        }

        return $date;
    }

    private static function bounds(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $days = (int) $start->setTime(0, 0)->diff($end->setTime(0, 0))->format('%r%a') + 1;
        if ($days < 1 || $days > 366) {
            throw new \InvalidArgumentException('Each reporting scope must contain 1–366 calendar days.');
        }

        return $days;
    }

    private static function month(\DateTimeImmutable $date, int $offset): \DateTimeImmutable
    {
        $start = $date->modify('first day of this month')->modify(($offset < 0 ? '' : '+').$offset.' months');

        return $start->setDate((int) $start->format('Y'), (int) $start->format('n'), min((int) $date->format('j'), (int) $start->format('t')));
    }

    public static function resolve(array $params, \DateTimeImmutable $generated, string $timezone, int $weekStart): array
    {
        $params = self::parameters($params);
        if ($weekStart < 0 || $weekStart > 6) {
            throw new \InvalidArgumentException('Invalid calendar week start.');
        }
        $tz = new \DateTimeZone($timezone);
        $now = $generated->setTimezone($tz);
        $today = $now->setTime(0, 0);
        $key = $params['window'];
        $mode = $params['comparison'] ?? 'previous_period';
        $anchor = isset($params['anchor_date']) ? self::date($params['anchor_date'], $tz) : $today;
        if ($anchor > $today) {
            throw new \InvalidArgumentException('Future reporting dates are unavailable.');
        }
        $kind = str_replace(['this_', 'last_'], '', $key);
        $step = 0;
        if (in_array($kind, ['week', 'month', 'quarter', 'year'], true)) {
            if ($kind === 'week') {
                $start = $anchor->modify('-'.(((int) $anchor->format('w') - $weekStart + 7) % 7).' days');
                $end = $start->modify('+6 days')->setTime(23, 59, 59);
                $step = 7;
            } else {
                $months = ['month' => 1, 'quarter' => 3, 'year' => 12][$kind];
                $month = $kind === 'year' ? 1 : ($kind === 'quarter' ? 1 + 3 * intdiv((int) $anchor->format('n') - 1, 3) : (int) $anchor->format('n'));
                $start = $anchor->setDate((int) $anchor->format('Y'), $month, 1);
                $end = $start->modify('+'.$months.' months')->modify('-1 day')->setTime(23, 59, 59);
                $step = -$months;
            }
            if (str_starts_with($key, 'last_')) {
                $start = $step > 0 ? $start->modify('-7 days') : self::month($start, $step);
                $end = $step > 0 ? $start->modify('+6 days')->setTime(23, 59, 59) : $start->modify('+'.(-$step).' months')->modify('-1 day')->setTime(23, 59, 59);
            }
            $label = ucfirst(str_replace('_', ' ', $key));
            if (in_array($key, ['week', 'month', 'quarter', 'year'], true)) {
                $label = $kind === 'week' ? 'Week of '.$start->format('Y-m-d') : ($kind === 'month' ? $start->format('F Y') : ($kind === 'quarter' ? 'Q'.(1 + intdiv((int) $start->format('n') - 1, 3)).' '.$start->format('Y') : $start->format('Y')));
            }
        } elseif ($key === 'custom') {
            $start = self::date($params['date_from'], $tz);
            $end = self::date($params['date_to'], $tz)->setTime(23, 59, 59);
            if ($end->format('Y-m-d') > $today->format('Y-m-d')) {
                throw new \InvalidArgumentException('Future reporting dates are unavailable.');
            }
            $label = 'Custom period';
        } else {
            $days = ['today' => 1, 'yesterday' => 1, 'last_7_days' => 7, 'last_30_days' => 30][$key];
            $start = $today->modify('-'.($days - 1).' days');
            $end = $today->setTime(23, 59, 59);
            if ($key === 'yesterday') {
                $start = $today->modify('-1 day');
                $end = $start->setTime(23, 59, 59);
            }
            $label = ['today' => 'Today', 'yesterday' => 'Yesterday', 'last_7_days' => 'Last 7 days', 'last_30_days' => 'Last 30 days'][$key];
        }
        if ($start > $now) {
            throw new \InvalidArgumentException('Future reporting dates are unavailable.');
        }
        $partial = $end > $now;
        
        $end = $partial ? $now : $end;
        $days = self::bounds($start, $end);
        $shorter = false;
        if ($mode === 'custom') {
            $priorStart = self::date($params['comparison_from'], $tz);
            $priorEnd = self::date($params['comparison_to'], $tz)->setTime(23, 59, 59);
            if ($priorEnd->format('Y-m-d') > $today->format('Y-m-d')) {
                throw new \InvalidArgumentException('Future comparison dates are unavailable.');
            }
            if ($priorEnd > $now) {
                $priorEnd = $now;
            }
        } elseif ($mode === 'previous_year') {
            $priorStart = self::month($start, -12);
            $priorEnd = self::month($end, -12);
            $shorter = $priorStart->format('m-d') !== $start->format('m-d') || $priorEnd->format('m-d') !== $end->format('m-d');
            if ($priorEnd->format('j') !== $end->format('j')) { $priorEnd = $priorEnd->setTime(23, 59, 59); }
        } elseif ($step < 0) {
            $priorStart = self::month($start, $step);
            $priorLimit = $start->modify('-1 day')->setTime(23, 59, 59);
            $priorEnd = $partial ? self::month($end, $step) : $priorLimit;
            if ($priorEnd > $priorLimit) {
                $priorEnd = $priorLimit;
            }
            $shorter = $partial && $priorEnd->format('j') !== $end->format('j');
            if ($shorter) { $priorEnd = $priorEnd->setTime(23, 59, 59); }
        } else {
            $shift = $step > 0 ? 7 : $days;
            $priorStart = $start->modify('-'.$shift.' days');
            $priorEnd = $end->modify('-'.$shift.' days');
        }
        $priorDays = self::bounds($priorStart, $priorEnd);
        $granularity = max($days, $priorDays) <= 32 ? 'day' : (max($days, $priorDays) <= 217 ? 'week' : 'month');
        $make = static fn ($k, $l, $s, $e) => ['key' => $k, 'label' => $l, 'start' => $s->format('c'), 'end' => $e->format('c'), 'timezone' => $tz->getName()];

        return ['windows' => [$make($key, $label, $start, $end), $make($mode === 'none' ? 'comparison_disabled' : ($mode === 'previous_year' ? 'previous_year' : ($mode === 'custom' ? 'custom_comparison' : 'previous_equivalent_window')), $mode === 'none' ? 'No comparison' : ($mode === 'previous_year' ? 'Same period last year' : ($mode === 'custom' ? 'Custom comparison' : 'Previous equivalent period')), $priorStart, $priorEnd)],
            'context' => ['week_starts_on' => $weekStart, 'comparison_mode' => $mode, 'period_to_date' => $partial, 'comparison_shorter' => $shorter || ($mode !== 'none' && $priorDays < $days), 'chart_granularity' => $granularity]];
    }

    public static function bucket(string $day, array $window, array $context): string
    {
        $date = self::date($day, new \DateTimeZone($window['timezone']));
        if ($context['chart_granularity'] === 'month') {
            $date = $date->modify('first day of this month');
        } elseif ($context['chart_granularity'] === 'week') {
            $date = $date->modify('-'.(((int) $date->format('w') - $context['week_starts_on'] + 7) % 7).' days');
        }

        return max($date->format('Y-m-d'),substr($window['start'],0,10));
    }
}
