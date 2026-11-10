<?php

use App\Services\ReservationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:expire', function (ReservationService $service) {
    $this->info($service->expire().' holds expired.');
})->purpose('Release expired booking holds');
Schedule::command('bookings:expire')->everyMinute()->withoutOverlapping();
