<?php

use App\Http\Controllers\PublicPageController;
use Illuminate\Support\Facades\Route;

foreach (['home' => '/', 'about' => '/about', 'rates' => '/rates', 'contact' => '/contact', 'privacy' => '/privacy', 'booking' => '/booking', 'tournaments' => '/tournaments'] as $page => $path) {
    Route::get($path, [PublicPageController::class, 'show'])->defaults('page', $page)->name($page);
}
