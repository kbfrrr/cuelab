<?php

namespace Tests\Feature;

use App\Filament\Resources\AvailabilityBlocks\Pages\ManageAvailabilityBlocks;
use App\Filament\Resources\BilliardTables\Pages\ManageBilliardTables;
use App\Filament\Resources\BusinessSettings\Pages\ManageBusinessSettings;
use App\Filament\Resources\OperatingHours\Pages\ManageOperatingHours;
use App\Filament\Resources\ServiceRates\Pages\ManageServiceRates;
use App\Filament\Resources\StaffActions\Pages\ManageStaffActions;
use App\Filament\Resources\Tournaments\Pages\ManageTournaments;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Models\StaffAction;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_actions_describe_recorded_changes_in_plain_language(): void
    {
        $staff = User::factory()->create(['is_staff' => true]);
        $approval = StaffAction::factory()->for($staff, 'user')->create([
            'action' => 'tournament_payment_proof.approved',
            'subject_type' => TournamentRegistration::class,
            'subject_id' => 21,
        ]);
        StaffAction::factory()->for($staff, 'user')->create([
            'action' => 'reservations.updated',
            'subject_type' => Reservation::class,
            'subject_id' => 42,
            'metadata' => ['fields' => ['status', 'updated_at'], 'status' => 'confirmed'],
        ]);
        StaffAction::factory()->for($staff, 'user')->create([
            'action' => 'tournament_registrations.updated',
            'subject_type' => TournamentRegistration::class,
            'subject_id' => 23,
            'metadata' => ['fields' => ['phone'], 'status' => 'unpaid'],
        ]);
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ManageStaffActions::class)
            ->assertSee('Approved payment proof')
            ->assertSee('Updated reservation')
            ->assertSee('Confirmed')
            ->assertSee('#42')
            ->assertDontSee('reservations.updated')
            ->assertDontSee('Unpaid')
            ->searchTable('21')
            ->assertCanSeeTableRecords([$approval])
            ->assertDontSee('Updated reservation');
    }

    public function test_admin_requires_staff_even_in_local_environment(): void
    {
        config(['app.env' => 'local']);
        $this->get('/admin')->assertRedirect('/admin/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $user->forceFill(['is_staff' => true])->save();
        foreach (['/admin', '/admin/business-settings', '/admin/operating-hours', '/admin/service-rates', '/admin/billiard-tables', '/admin/reservations', '/admin/availability-blocks', '/admin/staff-actions'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_staff_logout_redirects_to_landing_page(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]))
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirectToRoute('home');

        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_staff_flag_cannot_be_set_by_mass_assignment(): void
    {
        $user = new User;
        $user->fill(['name' => 'Guest', 'email' => 'guest@example.test', 'password' => 'not-a-real-password', 'is_staff' => true]);
        $this->assertFalse((bool) $user->is_staff);
    }

    public function test_staff_settings_edits_preserve_boolean_values_and_record_audit_history(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $setting = BusinessSetting::create(['key' => 'booking_enabled', 'value' => false, 'is_verified' => true]);
        Livewire::test(ManageBusinessSettings::class)
            ->callTableAction('edit', $setting, ['value' => 'true', 'is_verified' => true])->assertHasNoErrors();
        $this->assertTrue($setting->fresh()->value);
        $this->assertDatabaseHas('staff_actions', ['action' => 'business_settings.updated', 'subject_id' => $setting->id]);
    }

    public function test_hold_setting_rejects_invalid_values(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $setting = BusinessSetting::create(['key' => 'hold_minutes', 'value' => 60, 'is_verified' => true]);
        Livewire::test(ManageBusinessSettings::class)
            ->callTableAction('edit', $setting, ['value' => '0', 'is_verified' => true])->assertHasErrors();
        $this->assertSame(60, $setting->fresh()->value);
    }

    public function test_staff_enters_rates_in_pesos_and_storage_preserves_centavos(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $rate = ServiceRate::factory()->create();
        Livewire::test(ManageServiceRates::class)
            ->callTableAction('edit', $rate, ['amount_minor' => '45.50'])->assertHasNoErrors();
        $this->assertSame(4550, $rate->fresh()->amount_minor);
    }

    public function test_staff_can_add_a_billiard_table_without_entering_a_type(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $rate = ServiceRate::factory()->create();

        Livewire::test(ManageBilliardTables::class)
            ->mountAction('create')
            ->setActionData(['name' => 'Table 6', 'service_rate_id' => $rate->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('billiard_tables', [
            'name' => 'Table 6',
            'type' => 'Standard',
            'service_rate_id' => $rate->id,
        ]);
    }

    public function test_availability_picker_rejects_an_end_before_the_start(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $start = now()->addWeek()->setTime(18, 30);

        Livewire::test(ManageAvailabilityBlocks::class)
            ->mountAction('create')
            ->setActionData([
                'type' => 'closure',
                'reason' => 'Venue maintenance',
                'starts_at' => $start->toDateTimeString(),
                'ends_at' => $start->copy()->subHour()->toDateTimeString(),
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['ends_at' => 'after']);

        $this->assertDatabaseCount('availability_blocks', 0);
    }

    public function test_tournament_schedule_picker_saves_and_reopens_with_seconds(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $start = now()->addWeek()->setTime(18, 30, 45);
        $tournament = Tournament::factory()->create([
            'rules' => 'Standard venue rules',
            'registration_deadline' => $start->copy()->subDay(),
        ]);

        Livewire::test(ManageTournaments::class)
            ->callTableAction('edit', $tournament, [
                'starts_at' => $start->toDateTimeString(),
                'ends_at' => $start->copy()->addHours(3)->toDateTimeString(),
            ])
            ->assertHasNoErrors();

        $this->assertSame($start->toDateTimeString(), $tournament->fresh()->starts_at->toDateTimeString());
        Livewire::test(ManageTournaments::class)
            ->mountTableAction('edit', $tournament->fresh())
            ->assertTableActionDataSet(['starts_at' => $start->toDateTimeString()]);
    }

    public function test_operating_hours_picker_saves_and_reopens_overnight_times(): void
    {
        $this->actingAs(User::factory()->create(['is_staff' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $hours = OperatingHour::factory()->create(['day_of_week' => 4, 'opens_at' => '15:00', 'closes_at' => '01:00']);
        $opens = now()->setTime(16, 0)->toDateTimeString();
        $closes = now()->setTime(2, 0)->toDateTimeString();

        Livewire::test(ManageOperatingHours::class)
            ->callTableAction('edit', $hours, [
                'is_closed' => false,
                'opens_at' => $opens,
                'closes_at' => $closes,
            ])
            ->assertHasNoErrors();

        $hours->refresh();
        $this->assertSame('16:00', substr($hours->opens_at, 0, 5));
        $this->assertSame('02:00', substr($hours->closes_at, 0, 5));
        Livewire::test(ManageOperatingHours::class)
            ->mountTableAction('edit', $hours)
            ->assertTableActionDataSet(['opens_at' => '16:00', 'closes_at' => '02:00']);
    }
}
