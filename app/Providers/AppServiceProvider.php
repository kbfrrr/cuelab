<?php

namespace App\Providers;

use App\Filament\StaffLogoutResponse;
use App\Models\AvailabilityBlock;
use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Observers\StaffAuditObserver;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LogoutResponse::class, StaffLogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['review' => 20, 'store' => 10, 'lookup' => 10, 'status' => 30] as $endpoint => $attempts) {
            RateLimiter::for('booking-'.$endpoint, fn (Request $request) => Limit::perMinute($attempts)->by($request->ip()));
        }
        RateLimiter::for('cuechat', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        foreach (['lookup', 'register'] as $endpoint) {
            RateLimiter::for('tournament-'.$endpoint, fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        }
        RateLimiter::for('tournament-proof', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('session:'.$request->session()->getId()),
        ]);
        foreach ([Tournament::class, TournamentRegistration::class, BusinessSetting::class, OperatingHour::class, ServiceRate::class, BilliardTable::class, Reservation::class, AvailabilityBlock::class] as $model) {
            $model::observe(StaffAuditObserver::class);
        }
    }
}
