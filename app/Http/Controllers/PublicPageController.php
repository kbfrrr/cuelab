<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Models\OperatingHour;
use App\Models\ServiceRate;
use App\Models\Tournament;
use App\Services\ReservationService;
use Illuminate\Database\QueryException;
use Illuminate\View\View;

class PublicPageController extends Controller
{
    public function show(string $page = 'home'): View
    {
        $tournaments = collect();
        $settings = [];
        $rates = collect();
        $hours = collect();
        $informationUnavailable = false;
        $bookingReady = false;
        try {
            $tournaments = Tournament::publicSchedule()->get();
            $bookingReady = app(ReservationService::class)->enabled();
            $settings = BusinessSetting::where('is_verified', true)->get()->mapWithKeys(fn (BusinessSetting $setting): array => [$setting->key => $setting->value])->all();
            $rates = ServiceRate::where('is_verified', true)->where('is_active', true)->orderBy('name')->get();
            $hours = OperatingHour::where('is_verified', true)
                ->whereBetween('day_of_week', [0, 6])
                ->orderBy('day_of_week')
                ->get()
                ->filter(fn (OperatingHour $hour): bool => $hour->is_closed || (filled($hour->opens_at) && filled($hour->closes_at)));
        } catch (QueryException $exception) {
            report($exception);
            $informationUnavailable = true;
        }

        return view('pages.'.$page, compact('tournaments', 'settings', 'rates', 'hours', 'informationUnavailable', 'bookingReady'));
    }
}
