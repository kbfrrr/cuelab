<x-filament-panels::page>
    @php
        $peak = max(1, max(array_map('array_sum', $report['daily'])));
        $bookingLabels = \App\Services\StaffReportService::BOOKING_STATUSES;
        $metrics = [
            'Bookings recorded' => number_format($report['bookings']),
            'Scheduled table hours' => number_format($report['hours'], 2),
            'Confirmed table quotes' => '₱'.number_format($report['quotes'] / 100, 2),
            'Tournament registrations' => number_format($report['registrations']),
            'Gross recorded entry payments' => '₱'.number_format($report['payments'] / 100, 2),
        ];
    @endphp

    <div class="cl-analytics cl-reports">
        <div class="cl-panel cl-report-controls">
            <div class="cl-report-toolbar">
                <div class="cl-report-period">
                    <x-staff-period-dropdown label="Report period" :selected="$period"
                        :options="['7' => 'Last 7 days', '30' => 'Last 30 days', 'custom' => 'Custom range']" />
                    <p class="cl-report-dates" role="status">
                        @if ($period === 'custom' && ($from !== $appliedFrom || $to !== $appliedTo))
                            <span>Showing </span>
                        @endif
                        {{ $start->format('M j, Y') }} – {{ $end->format('M j, Y') }} · Asia/Manila
                    </p>
                </div>
                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadCsv" wire:loading.attr="disabled" wire:target="downloadCsv,applyRange,period">
                    Download CSV
                </x-filament::button>
            </div>
            @if ($period === 'custom')
                <form wire:submit="applyRange" class="cl-report-filters">
                    <div class="cl-report-range-fields">{{ $this->rangeFields }}</div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="applyRange,period">Apply range</x-filament::button>
                </form>
            @endif
        </div>

        <dl class="cl-report-metrics">
            @foreach ($metrics as $label => $value)
                <div class="cl-metric"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
        @if (! $report['bookings'] && ! $report['tables'] && ! $report['events'])
            <p class="cl-panel cl-report-empty">No activity in this date range. Try choosing different dates.</p>
        @endif

        <section class="cl-panel cl-report-section" aria-labelledby="report-daily">
            <h2 id="report-daily">Daily booking activity</h2>
            @if ($report['bookings'])
                <p>Requests and walk-ins combined, by date recorded.</p>
                <div class="cl-report-chart" x-data="{ selectedDay: null }">
                    @foreach ($report['daily'] as $date => $counts)
                        @php
                            $count = array_sum($counts);
                            $countLabel = trans_choice('{0} 0 bookings|{1} 1 booking|[2,*] :count bookings', $count);
                        @endphp
                        <button type="button" class="cl-report-chart-day" wire:key="chart-{{ $date }}"
                            aria-label="{{ $date }}, {{ $countLabel }}"
                            x-bind:aria-pressed="selectedDay === @js($date) ? 'true' : 'false'"
                            x-on:click="selectedDay = selectedDay === @js($date) ? null : @js($date)">
                            <span class="cl-report-chart-bar" aria-hidden="true" style="height: {{ $count / $peak * 100 }}%">
                                <span class="cl-report-chart-count">{{ $count }}</span>
                            </span>
                            <span class="cl-report-chart-date" aria-hidden="true">{{ substr($date, 5) }}</span>
                        </button>
                    @endforeach
                </div>
                <details class="cl-report-exact" wire:ignore.self>
                    <summary>View daily counts</summary>
                    <div class="cl-table-scroll">
                        <table class="cl-table">
                            <caption class="sr-only">Daily booking counts</caption>
                            <thead><tr><th scope="col">Date</th><th scope="col">Requests</th><th scope="col">Walk-ins</th><th scope="col">Total</th></tr></thead>
                            <tbody>
                                @foreach ($dailyRows as $date => $counts)
                                    <tr wire:key="daily-{{ $date }}"><td>{{ $date }}</td><td>{{ $counts['requests'] }}</td><td>{{ $counts['walk_ins'] }}</td><td>{{ array_sum($counts) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($dailyLastPage > 1)
                        <nav class="cl-report-pagination" aria-label="Daily counts pagination">
                            <span role="status">Days {{ $dailyFirst }}–{{ $dailyLast }} of {{ $dailyTotal }}</span>
                            <div>
                                <x-filament::button color="gray" size="sm" wire:click="changeDailyPage({{ $dailyPage - 1 }})" :disabled="$dailyPage === 1">Previous</x-filament::button>
                                <x-filament::button color="gray" size="sm" wire:click="changeDailyPage({{ $dailyPage + 1 }})" :disabled="$dailyPage === $dailyLastPage">Next</x-filament::button>
                            </div>
                        </nav>
                    @endif
                </details>
            @else
                <p>No bookings were recorded in this period.</p>
            @endif
        </section>

        <div class="cl-report-breakdowns">
            <section class="cl-panel cl-report-section" aria-labelledby="report-status">
                <h2 id="report-status">Reservation status</h2>
                <p>Current status of bookings recorded in this period.</p>
                <table class="cl-table">
                    <thead><tr><th scope="col">Status</th><th scope="col">Count</th></tr></thead>
                    <tbody>
                        @foreach ($report['bookingStatuses'] as $status => $count)
                            <tr><td>{{ $bookingLabels[$status] ?? $status }}</td><td>{{ $count }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
            <section class="cl-panel cl-report-section" aria-labelledby="report-tables">
                <h2 id="report-tables">Table activity</h2>
                <p>Scheduled hours count time within the range. Quotes count on the session’s start date and represent quoted charges.</p>
                @if ($report['tables'])
                    <div class="cl-table-scroll">
                        <table class="cl-table">
                            <thead><tr><th scope="col">Table</th><th scope="col">Scheduled hours</th><th scope="col">Confirmed quotes</th></tr></thead>
                            <tbody>
                                @foreach ($report['tables'] as $table)
                                    <tr><td>{{ $table['name'] }}</td><td>{{ number_format($table['seconds'] / 3600, 2) }}</td><td>₱{{ number_format($table['quotes'] / 100, 2) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>No confirmed table activity in this period.</p>
                @endif
            </section>
        </div>

        <section class="cl-panel cl-report-section" aria-labelledby="report-tournaments">
            <h2 id="report-tournaments">Tournament activity</h2>
            <p>Registrations are counted by signup date. Gross payments are counted by payment date and include payments on later cancellations. Refunds are handled separately.</p>
            @if ($report['events'])
                <div class="cl-table-scroll">
                    <table class="cl-table">
                        <thead><tr><th scope="col">Tournament</th><th scope="col">Registrations</th><th scope="col">Paid</th><th scope="col">Unpaid</th><th scope="col">Cancelled</th><th scope="col">Gross recorded payments</th></tr></thead>
                        <tbody>
                            @foreach ($report['events'] as $event)
                                <tr>
                                    <td>{{ $event['name'] }}</td><td>{{ array_sum($event['statuses']) }}</td><td>{{ $event['statuses']['paid'] }}</td>
                                    <td>{{ $event['statuses']['unpaid'] }}</td><td>{{ $event['statuses']['cancelled'] }}</td><td>₱{{ number_format($event['payments'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No tournament registrations or recorded payments in this period.</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
