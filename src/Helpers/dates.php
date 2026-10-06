<?php

declare(strict_types=1);

if (!function_exists('getDateTime')) {
    /** A date string in the app timezone (`app.timezone`, or a given one): `getDateTime('now', 'Y-m-d')`. */
    function getDateTime(string $dateString = 'now', string $format = 'Y-m-d H:i:s', ?string $timezone = null): string
    {
        $d = new DateTime($dateString);
        return $d->setTimezone(new DateTimeZone($timezone ?? (string) config('app.timezone', 'UTC')))->format($format);
    }
}

if (!function_exists('__fixDate')) {
    /** "mm/dd/yyyy" or "mm-dd-yyyy" => "Y-m-d" (the current date when it can't be read). */
    function __fixDate(string $dateString): string
    {
        $data = str_contains($dateString, '/') ? explode('/', $dateString) : explode('-', $dateString);
        if (count($data) < 3) return date('Y-m-d');
        return date('Y-m-d', strtotime($data[2] . $data[0] . $data[1]));
    }
}

if (!function_exists('dateDiff')) {
    function dateDiff(string $dateTime, string $futureDateTime): DateInterval
    {
        return (new DateTime($dateTime))->diff(new DateTime(date('Y-m-d H:i:s', strtotime($futureDateTime))));
    }
}

if (!function_exists('modifyDate')) {
    /** Add or subtract an ISO-8601 interval part: `modifyDate('1M', '2024-01-31')`, `modifyDate('7D', 'now', 'sub')`. */
    function modifyDate(string $interval, string $dateTime = 'now', string $type = 'add', string $format = 'Y-m-d H:i:s'): string
    {
        if (!in_array($type, ['add', 'sub'], true)) throw new InvalidArgumentException('Type must be "add" or "sub".');
        return (new DateTime($dateTime))->$type(new DateInterval('P' . $interval))->format($format);
    }
}

if (!function_exists('getMonthLastDay')) {
    function getMonthLastDay(string $dateString = 'now'): string
    {
        return (new DateTime(getDateTime($dateString)))->modify('last day of this month')->format('Y-m-d H:i:s');
    }
}

if (!function_exists('getFirstLast_monthDate')) {
    /** First (or last) date of a month number in a year (default: this year). */
    function getFirstLast_monthDate(int|string $month, bool $firstDate = true, ?int $year = null): string
    {
        $month = (int) $month;
        $year = getDateTime($year ? "$year-01-01" : 'now', 'Y');
        return date($firstDate ? 'Y-m-01' : 'Y-m-t', strtotime("$year-$month-04"));
    }
}

if (!function_exists('getMonthsInRange')) {
    /** The months between two dates: [['month' => '2024-01'], ...] or, without `$concat`, [['year' => .., 'month' => ..]]. */
    function getMonthsInRange(string $startDate, string $endDate, bool $concat = true): array
    {
        $months = [];
        while (strtotime($startDate) <= strtotime($endDate)) {
            $months[] = $concat
                ? ['month' => date('Y-m', strtotime($startDate))]
                : ['year' => date('Y', strtotime($startDate)), 'month' => date('m', strtotime($startDate))];
            $startDate = date('01 M Y', strtotime($startDate . '+ 1 month'));
        }
        return $months;
    }
}

if (!function_exists('getYearMonths')) {
    /** The twelve months of this year as `[$monthName => 'Jan-2024', ...extra]` rows. */
    function getYearMonths(string $monthName = 'month', array ...$data): array
    {
        $months = [];
        for ($i = 1; $i < 13; $i++) {
            $months[] = array_merge([$monthName => date('M-Y', strtotime('01-' . $i . '-' . date('Y')))], ...$data);
        }
        return $months;
    }
}

if (!function_exists('__useMonth')) {
    function __useMonth(string $dateString, string $format = 'M-Y'): string
    {
        return getDateTime($dateString, $format);
    }
}

if (!function_exists('__dueIn')) {
    /** "Due In 3 Days", or with `$type = 'overdue'`: "3 Days Overdue". */
    function __dueIn(DateInterval $days, ?string $type = null): string
    {
        $n = (int) $days->days;
        $due = $n < 1 ? 'Due Today' : 'Due in ' . $n . ' ' . ($n > 1 ? 'Days ' : 'Day');
        $remaining = $type === 'overdue'
            ? $n . ($n > 1 ? ' days' : ' day') . ' overdue'
            : ($n < 1 ? 'Due today' : $n . ' ' . ($n > 1 ? 'days remaining' : 'day remaining'));
        return trim(__ucwords($type === null ? $due : $remaining));
    }
}
