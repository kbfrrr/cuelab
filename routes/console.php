<?php

use App\Services\ReservationService;
use App\Services\TournamentPaymentProofService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:expire', function (ReservationService $service) {
    $this->info($service->expire().' holds expired.');
})->purpose('Release expired booking holds');
Schedule::command('bookings:expire')->everyMinute()->withoutOverlapping();

Artisan::command('tournaments:purge-proof-images', function (TournamentPaymentProofService $paymentProofs) {
    $this->info($paymentProofs->purgeExpiredImages().' expired payment screenshots removed.');
})->purpose('Remove tournament screenshots 30 days after the event ends');
Schedule::command('tournaments:purge-proof-images')->daily()->withoutOverlapping();
