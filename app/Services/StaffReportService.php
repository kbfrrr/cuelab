<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\TournamentRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StaffReportService
{
    public const TIMEZONE = 'Asia/Manila';

    public const BOOKING_STATUSES = [
        'confirmed' => 'Confirmed', 'pending' => 'Pending approval', 'cancelled' => 'Cancelled',
        'rejected' => 'Rejected', 'expired' => 'Expired', 'no_show' => 'No-show',
    ];

    public const ENTRY_STATUSES = ['paid' => 'Paid', 'unpaid' => 'Unpaid', 'cancelled' => 'Cancelled'];

    /** @return array{CarbonImmutable, CarbonImmutable} Inclusive start and exclusive end. */
    public function range(string $period, string $from = '', string $to = ''): array
    {
        $today = CarbonImmutable::today(self::TIMEZONE);
        if ($period === '7' || $period === '30') {
            return [$today->subDays((int) $period - 1), $today->addDay()];
        }

        Validator::make(compact('period', 'from', 'to'), [
            'period' => ['required', 'in:custom'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();

        $start = CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($to, self::TIMEZONE)->startOfDay()->addDay();
        if ($end->gt($start->addDays(90))) {
            throw ValidationException::withMessages(['to' => 'Choose a range of up to 90 days.']);
        }

        return [$start, $end];
    }

    public function summarize(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $start->setTimezone(config('app.timezone'));
        $until = $end->setTimezone(config('app.timezone'));
        $now = CarbonImmutable::now();
        $daily = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $daily[$day->toDateString()] = ['requests' => 0, 'walk_ins' => 0];
        }

        $bookingStatuses = array_fill_keys(array_keys(self::BOOKING_STATUSES), 0);
        $bookings = Reservation::query()->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->select(['id', 'source', 'status', 'hold_expires_at', 'created_at'])->lazyById();
        foreach ($bookings as $booking) {
            $status = $booking->status;
            if ($status === 'pending' && $booking->hold_expires_at?->lte($now)) {
                $status = 'expired';
            }
            $bookingStatuses[$status] = ($bookingStatuses[$status] ?? 0) + 1;
            $date = $booking->created_at->setTimezone(self::TIMEZONE)->toDateString();
            $daily[$date][$booking->source === 'walk_in' ? 'walk_ins' : 'requests']++;
        }

        $tables = [];
        $scheduled = Reservation::query()->where('status', 'confirmed')->overlapping($from, $until)
            ->select(['id', 'billiard_table_id', 'starts_at', 'ends_at', 'session_starts_at', 'session_ends_at', 'session_total_minor', 'quoted_total_minor'])
            ->with('billiardTable:id,name')->lazyById();
        foreach ($scheduled as $booking) {
            $id = $booking->billiard_table_id;
            $tables[$id] ??= ['name' => $booking->billiardTable->name, 'seconds' => 0, 'quotes' => 0];
            $effectiveStart = $booking->effectiveStart();
            $tables[$id]['seconds'] += max(0, $effectiveStart->max($from)->diffInSeconds($booking->effectiveEnd()->min($until), false));
            if ($effectiveStart->gte($from)) {
                $tables[$id]['quotes'] += $booking->session_total_minor ?? $booking->quoted_total_minor;
            }
        }
        uasort($tables, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $events = [];
        $entries = TournamentRegistration::query()->where(fn ($query) => $query
            ->where(fn ($query) => $query->where('created_at', '>=', $from)->where('created_at', '<', $until))
            ->orWhere(fn ($query) => $query->where('paid_at', '>=', $from)->where('paid_at', '<', $until)))
            ->select(['id', 'tournament_id', 'status', 'created_at', 'paid_at', 'quoted_fee_minor'])
            ->with('tournament:id,title')->lazyById();
        foreach ($entries as $entry) {
            $id = $entry->tournament_id;
            $events[$id] ??= ['name' => $entry->tournament->title, 'statuses' => array_fill_keys(array_keys(self::ENTRY_STATUSES), 0), 'payments' => 0];
            if ($entry->created_at->gte($from) && $entry->created_at->lt($until)) {
                $status = $entry->status;
                $events[$id]['statuses'][$status] = ($events[$id]['statuses'][$status] ?? 0) + 1;
            }
            if ($entry->paid_at?->gte($from) && $entry->paid_at->lt($until)) {
                $events[$id]['payments'] += $entry->quoted_fee_minor;
            }
        }
        uasort($events, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return [
            'daily' => $daily, 'bookingStatuses' => $bookingStatuses, 'tables' => $tables, 'events' => $events,
            'bookings' => array_sum($bookingStatuses),
            'hours' => array_sum(array_column($tables, 'seconds')) / 3600,
            'quotes' => array_sum(array_column($tables, 'quotes')),
            'registrations' => array_sum(array_map(fn (array $event): int => array_sum($event['statuses']), $events)),
            'payments' => array_sum(array_column($events, 'payments')),
        ];
    }

    public function csvRows(CarbonImmutable $start, CarbonImmutable $end, array $report): iterable
    {
        yield ['From', $start->toDateString()];
        yield ['To', $end->subDay()->toDateString()];
        yield ['Timezone', self::TIMEZONE];
        yield [];
        yield ['Summary', 'Total'];
        yield ['Bookings recorded', $report['bookings']];
        yield ['Scheduled table hours', round($report['hours'], 2)];
        yield ['Confirmed table quotes (PHP)', $report['quotes'] / 100];
        yield ['Tournament registrations', $report['registrations']];
        yield ['Gross recorded entry payments (PHP)', $report['payments'] / 100];
        yield [];
        yield ['Date', 'Requests', 'Walk-ins', 'Total'];
        foreach ($report['daily'] as $date => $counts) {
            yield [$date, $counts['requests'], $counts['walk_ins'], array_sum($counts)];
        }
        yield [];
        yield ['Reservation status', 'Count'];
        foreach ($report['bookingStatuses'] as $status => $count) {
            yield [self::BOOKING_STATUSES[$status] ?? $status, $count];
        }
        yield [];
        yield ['Table', 'Scheduled hours', 'Confirmed quotes (PHP)'];
        foreach ($report['tables'] as $table) {
            yield [$table['name'], round($table['seconds'] / 3600, 2), $table['quotes'] / 100];
        }
        yield [];
        yield ['Tournament', 'Registrations', 'Paid', 'Unpaid', 'Cancelled', 'Gross recorded payments (PHP)'];
        foreach ($report['events'] as $event) {
            yield [$event['name'], array_sum($event['statuses']), $event['statuses']['paid'], $event['statuses']['unpaid'], $event['statuses']['cancelled'], $event['payments'] / 100];
        }
    }
}
