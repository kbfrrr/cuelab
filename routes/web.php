<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\CueChatController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\TournamentController;
use App\Http\Middleware\PrivateBookingResponse;
use Illuminate\Support\Facades\Route;

foreach (['home' => '/', 'about' => '/about', 'rates' => '/rates', 'contact' => '/contact', 'privacy' => '/privacy', 'tournaments' => '/tournaments'] as $page => $path) {
    Route::get($path, [PublicPageController::class, 'show'])->defaults('page', $page)->name($page);
}

Route::middleware(PrivateBookingResponse::class)->group(function (): void {
    Route::post('/cuechat/message', CueChatController::class)->middleware('throttle:cuechat')->block(50, 25)->name('cuechat.message');
    Route::get('/booking', [BookingController::class, 'index'])->name('booking');
    Route::post('/booking/review', [BookingController::class, 'review'])->middleware('throttle:booking-review')->name('booking.review');
    Route::post('/booking', [BookingController::class, 'store'])->middleware('throttle:booking-store')->name('booking.store');
    Route::post('/booking/lookup', [BookingController::class, 'lookup'])->middleware('throttle:booking-lookup')->name('booking.lookup');
    Route::get('/booking/status', [BookingController::class, 'status'])->middleware('throttle:booking-status')->name('booking.status');

});

Route::middleware(PrivateBookingResponse::class)->group(function (): void {
    Route::get('/tournaments/registration/status', [TournamentController::class, 'status'])->name('tournament.status');
    Route::post('/tournaments/registration/lookup', [TournamentController::class, 'lookup'])->middleware('throttle:tournament-lookup')->name('tournament.lookup');
    Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournament.show');
    Route::post('/tournaments/{tournament}/register', [TournamentController::class, 'register'])->middleware('throttle:tournament-register')->name('tournament.register');
});
