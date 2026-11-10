<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\PublicPageController;
use App\Http\Middleware\PrivateBookingResponse;
use Illuminate\Support\Facades\Route;

foreach (['home' => '/', 'about' => '/about', 'rates' => '/rates', 'contact' => '/contact', 'privacy' => '/privacy', 'tournaments' => '/tournaments'] as $page => $path) {
    Route::get($path, [PublicPageController::class, 'show'])->defaults('page', $page)->name($page);
}

Route::middleware(PrivateBookingResponse::class)->group(function (): void {
    Route::get('/booking', [BookingController::class, 'index'])->name('booking');
    Route::post('/booking/review', [BookingController::class, 'review'])->middleware('throttle:booking-review')->name('booking.review');
    Route::post('/booking', [BookingController::class, 'store'])->middleware('throttle:booking-store')->name('booking.store');
    Route::post('/booking/lookup', [BookingController::class, 'lookup'])->middleware('throttle:booking-lookup')->name('booking.lookup');
    Route::get('/booking/status', [BookingController::class, 'status'])->middleware('throttle:booking-status')->name('booking.status');

});
