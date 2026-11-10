<?php

namespace Tests\Feature;

use App\Filament\Widgets\StaffAnalytics;
use App\Models\Reservation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_empty_states_and_working_navigation(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        $this->get('/admin')->assertOk()->assertSee('Venue overview')->assertSee('A clear schedule')->assertSee('No events on the board')->assertSee('Last 30 days');
    }

    public function test_period_filter_changes_request_totals_and_expired_holds_are_excluded(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Reservation::factory()->create(['created_at' => now()->subDays(15), 'status' => 'confirmed']);
        Reservation::factory()->create(['status' => 'pending', 'hold_expires_at' => now()->subMinute()]);
        Reservation::factory()->create(['status' => 'pending', 'hold_expires_at' => now()->addMinutes(30)]);
        $widget = Livewire::test(StaffAnalytics::class);
        $method = new \ReflectionMethod(StaffAnalytics::class, 'getViewData');
        $this->assertSame(3, $method->invoke($widget->instance())['metrics'][0]['value']);
        $this->assertSame(1, $method->invoke($widget->instance())['metrics'][2]['value']);
        $widget->set('period', '7')->assertHasNoErrors();
        $this->assertSame(2, $method->invoke($widget->instance())['metrics'][0]['value']);
        $widget->set('period', 'all');
        $this->assertSame(3, $method->invoke($widget->instance())['metrics'][0]['value']);
    }

    public function test_widget_rejects_non_staff_and_schedule_excludes_cancelled_bookings(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(StaffAnalytics::class)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $record = Reservation::factory()->create(['status' => 'confirmed', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        $cancelled = Reservation::factory()->create(['status' => 'cancelled', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        $widget = Livewire::test(StaffAnalytics::class);
        $data = (new \ReflectionMethod(StaffAnalytics::class, 'getViewData'))->invoke($widget->instance());
        $this->assertTrue($data['schedule']->contains('id', $record->id));
        $this->assertFalse($data['schedule']->contains('id', $cancelled->id));
    }
}
