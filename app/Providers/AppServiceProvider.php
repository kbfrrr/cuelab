<?php

namespace App\Providers;

use App\Models\AvailabilityBlock;
use App\Models\BilliardTable;
use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\Reservation;
use App\Models\ServiceRate;
use App\Observers\StaffAuditObserver;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['review' => 20, 'store' => 10, 'lookup' => 10, 'status' => 30] as $endpoint => $attempts) {
            RateLimiter::for('booking-'.$endpoint, fn (Request $request) => Limit::perMinute($attempts)->by($request->ip()));
        }
        foreach ([BusinessSetting::class, OperatingHour::class, ServiceRate::class, BilliardTable::class, Reservation::class, AvailabilityBlock::class] as $model) {
            $model::observe(StaffAuditObserver::class);
        }
    }
}
