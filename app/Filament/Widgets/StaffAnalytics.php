<?php

namespace App\Filament\Widgets;

use App\Models\BilliardTable;
use App\Models\Reservation;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

class StaffAnalytics extends Widget
{
    protected string $view = 'filament.widgets.staff-analytics';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public string $period = '30';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_staff;
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);
        $period = in_array($this->period, ['7', '30', 'all']) ? $this->period : '30';
        $since = $period === 'all' ? null : now()->subDays((int) $period - 1)->startOfDay();
        $bookings = Reservation::query()->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since));
        $registrations = TournamentRegistration::query()->where('status', '!=', 'cancelled')->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since));
        $metrics = [
            ['label' => 'Booking requests', 'value' => (clone $bookings)->count(), 'note' => 'Received in selected period', 'route' => 'reservations'],
            ['label' => 'Confirmed bookings', 'value' => (clone $bookings)->where('status', 'confirmed')->count(), 'note' => 'Including walk-ins', 'route' => 'reservations'],
            ['label' => 'Awaiting approval', 'value' => (clone $bookings)->where('status', 'pending')->where('hold_expires_at', '>', now())->count(), 'note' => 'Active holds in selected period', 'route' => 'reservations'],
            ['label' => 'Active tables', 'value' => BilliardTable::where('is_active', true)->count(), 'note' => 'Current venue inventory', 'route' => 'billiard-tables'],
            ['label' => 'Upcoming tournaments', 'value' => Tournament::whereIn('status', ['published', 'closed'])->where('starts_at', '>', now())->count(), 'note' => 'Current published schedule', 'route' => 'tournaments'],
            ['label' => 'Player registrations', 'value' => $registrations->count(), 'note' => 'Excludes cancelled entries', 'route' => 'tournament-registrations'],
        ];
        $counts = Reservation::whereBetween('created_at', [now()->subDays(6)->startOfDay(), now()])->selectRaw('date(created_at) as day, count(*) as total')->groupByRaw('date(created_at)')->pluck('total', 'day');
        $activity = collect(range(6, 0))->map(function (int $offset) use ($counts): array {
            $day = now()->subDays($offset);

            return ['label' => $day->format('D'), 'date' => $day->format('M j'), 'count' => (int) ($counts[$day->toDateString()] ?? 0)];
        });

        return ['metrics' => $metrics, 'activity' => $activity, 'peak' => max(1, $activity->max('count')),
            'schedule' => Reservation::with('billiardTable')->where('status', 'confirmed')
                ->whereRaw(Reservation::EFFECTIVE_END.' > ?', [now()])->orderByRaw(Reservation::EFFECTIVE_START)->limit(5)->get(),
            'events' => Tournament::whereIn('status', ['published', 'closed'])->where('ends_at', '>', now())->orderBy('starts_at')->limit(4)->get()];
    }
}
