<?php
// Events: one-off or repeating sessions that visitors confirm they're attending.
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function recurrence_options(): array
{
    return [
        'none'            => 'Does not repeat',
        'daily'           => 'Every day',
        'weekdays'        => 'Every weekday (Mon–Fri)',
        'weekly'          => 'Every week',
        'fortnightly'     => 'Every 2 weeks',
        'monthly_weekday' => 'Monthly on the same weekday (e.g. 2nd Tuesday)',
        'monthly_last'    => 'Monthly on the last same weekday (e.g. last Friday)',
        'monthly_date'    => 'Monthly on the same date (e.g. the 15th)',
    ];
}

function event_date(string $ymd): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return ($d && $d->format('Y-m-d') === $ymd) ? $d : null;
}

function event_skip_dates(array $e): array
{
    return array_filter(array_map('trim', explode(',', (string) ($e['skip_dates'] ?? ''))));
}

/** Does this event have a session on the given date? */
function event_occurs_on(array $e, DateTimeImmutable $day): bool
{
    $start = event_date($e['start_date']);
    $ymd = $day->format('Y-m-d');
    if (!$start || $ymd < $e['start_date']) {
        return false;
    }
    if (!empty($e['repeat_until']) && $ymd > $e['repeat_until']) {
        return false;
    }
    if (in_array($ymd, event_skip_dates($e), true)) {
        return false;
    }

    $sameWeekday = $day->format('N') === $start->format('N');
    switch ($e['recurrence']) {
        case 'none':
            return $ymd === $e['start_date'];
        case 'daily':
            return true;
        case 'weekdays':
            return (int) $day->format('N') <= 5;
        case 'weekly':
            return $sameWeekday;
        case 'fortnightly':
            // Count whole days via UTC so clock changes can't shift the count.
            $days = (int) round((strtotime($ymd . ' UTC') - strtotime($e['start_date'] . ' UTC')) / 86400);
            return $sameWeekday && $days % 14 === 0;
        case 'monthly_weekday': // same nth weekday of the month
            return $sameWeekday && intdiv((int) $day->format('j') - 1, 7) === intdiv((int) $start->format('j') - 1, 7);
        case 'monthly_last': // last such weekday of the month
            return $sameWeekday && (int) $day->format('j') + 7 > (int) $day->format('t');
        case 'monthly_date':
            return $day->format('j') === $start->format('j');
    }
    return false;
}

/** Human-readable summary, e.g. "Every week on Tuesday, 19:00–21:00". */
function event_schedule_text(array $e): string
{
    $start = event_date($e['start_date']);
    if (!$start) {
        return '';
    }
    $weekday = $start->format('l');
    $ordinals = ['1st', '2nd', '3rd', '4th', '5th'];
    $when = match ($e['recurrence']) {
        'none'            => $start->format('l j F Y'),
        'daily'           => 'Every day',
        'weekdays'        => 'Every weekday',
        'weekly'          => "Every {$weekday}",
        'fortnightly'     => "Every other {$weekday}",
        'monthly_weekday' => 'The ' . $ordinals[intdiv((int) $start->format('j') - 1, 7)] . " {$weekday} of each month",
        'monthly_last'    => "The last {$weekday} of each month",
        'monthly_date'    => 'The ' . $start->format('jS') . ' of each month',
        default           => '',
    };
    $text = $when . ', ' . $e['start_time'] . '–' . $e['end_time'];
    if ($e['recurrence'] !== 'none') {
        $text .= ' · from ' . $start->format('j M Y');
        if (!empty($e['repeat_until']) && ($until = event_date($e['repeat_until']))) {
            $text .= ' until ' . $until->format('j M Y');
        }
    }
    return $text;
}

/** Next session dates (Y-m-d) on or after $from. */
function event_next_dates(array $e, int $count = 3, ?DateTimeImmutable $from = null): array
{
    $day = ($from ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $dates = [];
    for ($i = 0; $i < 800 && count($dates) < $count; $i++, $day = $day->modify('+1 day')) {
        $ymd = $day->format('Y-m-d');
        if (($e['recurrence'] === 'none' && $ymd > $e['start_date'])
            || (!empty($e['repeat_until']) && $ymd > $e['repeat_until'])) {
            break; // no more sessions
        }
        if (event_occurs_on($e, $day)) {
            $dates[] = $ymd;
        }
    }
    return $dates;
}

/** Active events with a session on the given day, ordered by start time. */
function events_on(DateTimeImmutable $day): array
{
    $rows = db()->query('SELECT * FROM events WHERE active = 1 ORDER BY start_time, title')->fetchAll();
    return array_values(array_filter($rows, fn ($e) => event_occurs_on($e, $day)));
}

/**
 * Events a visitor could be arriving for right now: today's sessions, from
 * door_opens_before minutes before the start until door_closes_after minutes after the end.
 */
function events_open_now(): array
{
    $now = new DateTimeImmutable('now');
    $today = $now->setTime(0, 0);
    $before = (int) config('door_opens_before') ?: 0;
    $after = (int) config('door_closes_after') ?: 0;

    $open = [];
    foreach (events_on($today) as $e) {
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $today->format('Y-m-d') . ' ' . $e['start_time']);
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $today->format('Y-m-d') . ' ' . $e['end_time']);
        if (!$start || !$end) {
            continue;
        }
        if ($end <= $start) {
            $end = $end->modify('+1 day'); // runs past midnight
        }
        if ($now >= $start->modify("-{$before} minutes") && $now <= $end->modify("+{$after} minutes")) {
            $open[] = $e;
        }
    }
    return $open;
}

/** The next upcoming session of any active event: ['event' => row, 'start' => DateTimeImmutable] or null. */
function next_session(): ?array
{
    $now = new DateTimeImmutable('now');
    $best = null;
    foreach (db()->query('SELECT * FROM events WHERE active = 1')->fetchAll() as $e) {
        foreach (event_next_dates($e, 2) as $ymd) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $e['start_time']);
            if ($start && $start > $now && (!$best || $start < $best['start'])) {
                $best = ['event' => $e, 'start' => $start];
                break;
            }
        }
    }
    return $best;
}

/** Validates the event form; returns [cleanData, errors]. */
function validate_event(array $in): array
{
    $d = [
        'title'        => trim((string) ($in['title'] ?? '')),
        'location'     => trim((string) ($in['location'] ?? '')),
        'description'  => trim((string) ($in['description'] ?? '')),
        'start_date'   => trim((string) ($in['start_date'] ?? '')),
        'start_time'   => trim((string) ($in['start_time'] ?? '')),
        'end_time'     => trim((string) ($in['end_time'] ?? '')),
        'recurrence'   => (string) ($in['recurrence'] ?? 'none'),
        'repeat_until' => trim((string) ($in['repeat_until'] ?? '')),
        'skip_dates'   => '',
        'active'       => !empty($in['active']) ? 1 : 0,
    ];
    $errors = [];

    if ($d['title'] === '' || mb_strlen($d['title']) > 80) {
        $errors[] = 'Please give the event a title (up to 80 characters).';
    }
    if (mb_strlen($d['location']) > 120) {
        $errors[] = 'Location must be 120 characters or fewer.';
    }
    if (mb_strlen($d['description']) > 1000) {
        $errors[] = 'Details must be 1000 characters or fewer.';
    }
    if (!event_date($d['start_date'])) {
        $errors[] = 'Please choose a valid date.';
    }
    foreach (['start_time' => 'start', 'end_time' => 'end'] as $k => $label) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $d[$k])) {
            $errors[] = "Please enter a valid {$label} time.";
        }
    }
    if (!array_key_exists($d['recurrence'], recurrence_options())) {
        $errors[] = 'Please choose how often it repeats.';
    }
    if ($d['recurrence'] === 'none' || $d['repeat_until'] === '') {
        $d['repeat_until'] = null;
    } elseif (!event_date($d['repeat_until'])) {
        $errors[] = '"Repeat until" must be a valid date.';
    } elseif ($d['repeat_until'] < $d['start_date']) {
        $errors[] = '"Repeat until" must be after the first date.';
    }

    $skips = [];
    foreach (preg_split('/[\s,]+/', (string) ($in['skip_dates'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $s) {
        if (event_date($s)) {
            $skips[] = $s;
        } else {
            $errors[] = "Skip date \"{$s}\" isn't a valid date (use YYYY-MM-DD).";
        }
    }
    sort($skips);
    $d['skip_dates'] = $skips ? implode(',', array_unique($skips)) : null;

    return [$d, $errors];
}
