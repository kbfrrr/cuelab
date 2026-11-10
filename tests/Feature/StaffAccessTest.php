<?php

namespace Tests\Feature;

use App\Filament\Resources\BusinessSettings\Pages\ManageBusinessSettings;
use App\Filament\Resources\ServiceRates\Pages\ManageServiceRates;
use App\Models\BusinessSetting;
use App\Models\ServiceRate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

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
}
