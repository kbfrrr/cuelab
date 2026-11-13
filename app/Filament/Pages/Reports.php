<?php

namespace App\Filament\Pages;

use App\Services\StaffReportService;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Reports extends Page
{
    private const DAILY_ROWS_PER_PAGE = 7;

    protected static ?string $slug = 'reports';

    protected static ?string $title = 'Reports & Analytics';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.reports';

    public string $period = '30';

    public ?string $from = null;

    public ?string $to = null;

    #[Locked]
    public string $appliedFrom = '';

    #[Locked]
    public string $appliedTo = '';

    #[Locked]
    public int $dailyPage = 1;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_staff;
    }

    public function mount(StaffReportService $reports): void
    {
        $this->applyRange($reports);
    }

    public function updatedPeriod(string $period): void
    {
        if ($period === '7' || $period === '30') {
            $this->applyRange(app(StaffReportService::class));

            return;
        }

        $this->from = $this->appliedFrom;
        $this->to = $this->appliedTo;
        $this->resetValidation();
    }

    public function rangeFields(Schema $schema): Schema
    {
        return $schema->inlineLabel()->columns(2)->components([
            DatePicker::make('from')->native(false)->closeOnDateSelection()
                ->suffixIcon('heroicon-o-calendar-days', isInline: true)->required(),
            DatePicker::make('to')->native(false)->closeOnDateSelection()
                ->suffixIcon('heroicon-o-calendar-days', isInline: true)->required(),
        ]);
    }

    public function applyRange(StaffReportService $reports): void
    {
        abort_unless(static::canAccess(), 403);
        [$start, $end] = $reports->range(
            $this->period,
            preg_replace('/ 00:00:00$/D', '', $this->from ?? ''),
            preg_replace('/ 00:00:00$/D', '', $this->to ?? ''),
        );
        $this->appliedFrom = $start->toDateString();
        $this->appliedTo = $end->subDay()->toDateString();
        $this->from = $this->appliedFrom;
        $this->to = $this->appliedTo;
        $this->dailyPage = 1;
        $this->resetValidation();
    }

    public function changeDailyPage(int $page, StaffReportService $reports): void
    {
        abort_unless(static::canAccess(), 403);
        [$start, $end] = $reports->range('custom', $this->appliedFrom, $this->appliedTo);
        $lastPage = (int) ceil($start->diffInDays($end) / self::DAILY_ROWS_PER_PAGE);
        $this->dailyPage = max(1, min($page, $lastPage));
    }

    public function downloadCsv(StaffReportService $reports): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        [$start, $end] = $reports->range('custom', $this->appliedFrom, $this->appliedTo);
        $report = $reports->summarize($start, $end);

        return response()->streamDownload(function () use ($reports, $start, $end, $report): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            foreach ($reports->csvRows($start, $end, $report) as $row) {
                $safe = array_map(fn (mixed $value): mixed => is_string($value) && preg_match('/^(?:\s*[=+\-@]|\s*[\t\r\n])/u', $value)
                    ? "'".$value : $value, $row);
                fputcsv($output, $safe, escape: '');
            }
            fclose($output);
        }, "cuelab-report-{$this->appliedFrom}-to-{$this->appliedTo}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $reports = app(StaffReportService::class);
        [$start, $end] = $reports->range('custom', $this->appliedFrom, $this->appliedTo);

        $report = $reports->summarize($start, $end);
        $dailyTotal = count($report['daily']);
        $offset = ($this->dailyPage - 1) * self::DAILY_ROWS_PER_PAGE;

        return [
            'report' => $report, 'start' => $start, 'end' => $end->subDay(),
            'dailyRows' => array_slice($report['daily'], $offset, self::DAILY_ROWS_PER_PAGE, preserve_keys: true),
            'dailyTotal' => $dailyTotal, 'dailyFirst' => $offset + 1,
            'dailyLast' => min($offset + self::DAILY_ROWS_PER_PAGE, $dailyTotal),
            'dailyLastPage' => (int) ceil($dailyTotal / self::DAILY_ROWS_PER_PAGE),
        ];
    }
}
