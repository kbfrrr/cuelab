<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Models\BilliardTable;
use App\Models\Reservation;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use App\Services\StaffReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_staff_can_open_and_download_reports(): void
    {
        $this->get('/admin/reports')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/reports')->assertForbidden();
        Livewire::test(Reports::class)->assertForbidden();

        $this->actingAs(User::factory()->create(['is_staff' => true]));
        $this->travelTo(CarbonImmutable::parse('2026-11-14 16:00', 'Asia/Manila'));
        $this->get('/admin')->assertOk()->assertSee('Open Reports &amp; Analytics', false);
        $this->get('/admin/reports')->assertOk()->assertSee('Oct 16, 2026')->assertSee('No activity in this date range');

        Livewire::test(Reports::class)->set('period', '7')
            ->assertSet('appliedFrom', '2026-11-08')->assertSet('appliedTo', '2026-11-14')
            ->set('period', 'custom')->assertSet('from', '2026-11-08')->assertSet('to', '2026-11-14')
            ->set('from', '2026-01-01')->set('to', '2026-04-01')->call('applyRange')
            ->assertHasErrors('to')->assertSet('appliedFrom', '2026-11-08')
            ->call('downloadCsv')->assertFileDownloaded('cuelab-report-2026-11-08-to-2026-11-14.csv');
    }

    public function test_daily_counts_paginate_without_changing_the_applied_report_range(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 16:00', 'Asia/Manila'));
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Reservation::factory()->create(['created_at' => now()]);

        Livewire::test(Reports::class)
            ->assertSee('Days 1–7 of 30')
            ->call('changeDailyPage', 2)->assertSee('Days 8–14 of 30')->assertSet('appliedTo', '2026-11-14')
            ->call('changeDailyPage', 99)->assertSee('Days 29–30 of 30')
            ->call('downloadCsv')->assertFileDownloaded('cuelab-report-2026-10-16-to-2026-11-14.csv')
            ->set('period', '7')->call('applyRange')->assertSet('dailyPage', 1)->assertDontSee('Days 29–30 of 30');
    }

    public function test_report_counts_boundaries_overnight_sessions_and_payments_by_their_own_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 16:00', 'Asia/Manila'));
        $table = BilliardTable::factory()->create(['name' => 'Table 1']);
        Reservation::factory()->for($table)->create([
            'created_at' => '2026-11-12 23:59:59', 'status' => 'confirmed', 'source' => 'website',
            'starts_at' => '2026-11-12 23:00:00', 'ends_at' => '2026-11-13 01:00:00', 'quoted_total_minor' => 40000,
        ]);
        Reservation::factory()->for($table)->create([
            'created_at' => '2026-11-13 00:00:00', 'status' => 'confirmed', 'source' => 'walk_in',
            'starts_at' => '2026-11-13 23:00:00', 'ends_at' => '2026-11-14 00:30:00', 'quoted_total_minor' => 30000,
            'session_starts_at' => '2026-11-13 23:20:00', 'session_ends_at' => '2026-11-14 00:00:00', 'session_total_minor' => 15000,
        ]);
        $expired = Reservation::factory()->for($table)->create([
            'created_at' => '2026-11-13 23:59:59', 'status' => 'pending', 'source' => 'website', 'hold_expires_at' => '2026-11-14 12:00:00',
        ]);
        Reservation::factory()->for($table)->create([
            'created_at' => '2026-11-14 00:00:00', 'status' => 'confirmed',
            'starts_at' => '2026-11-14 00:00:00', 'ends_at' => '2026-11-14 01:00:00',
        ]);

        $event = Tournament::factory()->create(['title' => '8-ball Open']);
        TournamentRegistration::factory()->for($event)->create([
            'created_at' => '2026-11-12 18:00:00', 'status' => 'cancelled',
            'paid_at' => '2026-11-13 19:00:00', 'quoted_fee_minor' => 50000, 'payment_method' => 'gcash',
        ]);
        TournamentRegistration::factory()->for($event)->create([
            'created_at' => '2026-11-13 10:00:00', 'status' => 'paid',
            'paid_at' => '2026-11-14 00:00:00', 'quoted_fee_minor' => 20000, 'payment_method' => 'cash',
        ]);

        $service = app(StaffReportService::class);
        [$start, $end] = $service->range('custom', '2026-11-13', '2026-11-13');
        $report = $service->summarize($start, $end);
        $this->assertSame(['requests' => 1, 'walk_ins' => 1], $report['daily']['2026-11-13']);
        $this->assertSame(1, $report['bookingStatuses']['expired']);
        $this->assertSame('pending', $expired->fresh()->status);
        $this->assertEquals(6000, $report['tables'][$table->id]['seconds']);
        $this->assertSame(15000, $report['quotes']);
        $this->assertSame(1, $report['registrations']);
        $this->assertSame(50000, $report['payments']);

        $this->actingAs(User::factory()->create(['is_staff' => true]));
        $page = Livewire::test(Reports::class)->set('period', 'custom')->set('from', '2026-11-13')->set('to', '2026-11-13')
            ->call('applyRange')->assertHasNoErrors()->assertSee('₱150.00')->assertSee('₱500.00');
        $csv = $page->instance()->downloadCsv($service);
        ob_start();
        $csv->sendContent();
        $content = ob_get_clean();
        $this->assertStringContainsString('"Confirmed table quotes (PHP)",150', $content);
        $this->assertStringContainsString('"Gross recorded entry payments (PHP)",500', $content);
        $this->assertStringContainsString('2026-11-13,1,1,2', $content);
        $this->assertStringNotContainsString($expired->reference, $content);
    }
}
