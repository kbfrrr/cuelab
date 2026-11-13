<x-filament-widgets::widget>
<div class="cl-analytics">
    <div class="cl-toolbar">
        <div><h2>Venue overview</h2><p>Bookings, tables, and tournaments at a glance.</p>
            <a class="cl-reports-link" href="{{ \App\Filament\Pages\Reports::getUrl() }}">Open Reports &amp; Analytics</a>
        </div>
        <x-staff-period-dropdown label="Overview period" description="cl-period-note" :selected="$period"
            :options="['7' => 'Last 7 days', '30' => 'Last 30 days', 'all' => 'All time']" />
    </div>
    <p id="cl-period-note" class="cl-period-note">Period applies to booking requests and player registrations. Inventory and schedules show the current state.</p>
    <div class="cl-report-grid" wire:loading.class="cl-refreshing" wire:target="period">
        <div class="cl-metrics">
            @foreach($metrics as $metric)
            <a class="cl-metric" href="{{ route('filament.admin.resources.'.$metric['route'].'.index') }}">
                <span>{{ $metric['label'] }}</span><strong>{{ number_format($metric['value']) }}</strong><small>{{ $metric['note'] }}</small>
            </a>
            @endforeach
        </div>
        <section class="cl-panel cl-activity" aria-labelledby="cl-activity-title">
            <div class="cl-panel-heading"><h3 id="cl-activity-title">Booking activity</h3><span>Last 7 days</span></div>
            <p class="cl-chart-total"><strong>{{ $activity->sum('count') }}</strong> requests received</p>
            @if($activity->sum('count'))
            <div class="cl-bars" role="img" aria-label="Booking requests over the last seven days: {{ $activity->map(fn ($day) => $day['date'].': '.$day['count'])->join(', ') }}">
                @foreach($activity as $day)
                <div class="cl-bar-column"><span class="cl-bar-count">{{ $day['count'] }}</span><div class="cl-bar-track"><div class="cl-bar" style="height:{{ $day['count'] / $peak * 100 }}%"></div></div><span class="cl-bar-label">{{ $day['label'] }}</span></div>
                @endforeach
            </div>
            @else<div class="cl-chart-empty-state"><x-filament::icon icon="heroicon-o-chart-bar" /><p>No requests in the last 7 days.</p><small>Your first request will start the chart.</small></div>@endif
        </section>
    </div>
    <div class="cl-detail-grid">
        <section class="cl-panel" aria-labelledby="cl-schedule-title">
            <div class="cl-panel-heading"><h3 id="cl-schedule-title">Upcoming bookings</h3><a href="{{ route('filament.admin.resources.reservations.index') }}">View bookings <x-filament::icon icon="heroicon-o-arrow-up-right" /></a></div>
            @if($schedule->isEmpty())
            <div class="cl-empty"><x-filament::icon icon="heroicon-o-calendar-days" /><h4>A clear schedule</h4><p>Confirmed reservations and walk-ins will appear here.</p></div>
            @else
            <div class="cl-table-scroll"><table class="cl-table"><caption class="sr-only">Upcoming confirmed bookings, times in Asia/Manila</caption><thead><tr><th>Table</th><th>Starts</th><th>Ends</th><th>Status</th></tr></thead><tbody>@foreach($schedule as $booking)<tr><td>{{ $booking->billiardTable->name }}</td><td>{{ $booking->effectiveStart()->format('M j, g:i A') }}</td><td>{{ $booking->effectiveEnd()->format('M j, g:i A') }}</td><td><span class="cl-status">Confirmed</span></td></tr>@endforeach</tbody></table></div>
            @endif
        </section>
        <section class="cl-panel" aria-labelledby="cl-events-title">
            <div class="cl-panel-heading"><h3 id="cl-events-title">Tournament schedule</h3><a href="{{ route('filament.admin.resources.tournaments.index') }}">View events <x-filament::icon icon="heroicon-o-arrow-up-right" /></a></div>
            @forelse($events as $event)<a class="cl-event" href="{{ route('filament.admin.resources.tournaments.index') }}"><span class="cl-event-date"><small>{{ $event->starts_at->format('M') }}</small><strong>{{ $event->starts_at->format('d') }}</strong></span><span><strong>{{ $event->title }}</strong><small>{{ $event->starts_at->format('g:i A') }} · {{ $event->status==='closed' ? 'Registration closed' : 'Published' }}</small></span></a>@empty
            <div class="cl-empty"><x-filament::icon icon="heroicon-o-trophy" /><h4>No events on the board</h4><p>Publish a tournament to add it to this schedule.</p></div>
            @endforelse
        </section>
    </div>
    <p class="cl-footnote">Schedule times are shown in Asia/Manila.</p>
</div>
</x-filament-widgets::widget>
