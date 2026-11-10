<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function __construct(private ReservationService $reservations) {}

    public function index(Request $request): View|RedirectResponse
    {
        $enabled = $this->reservations->enabled();
        $tables = collect();
        if ($enabled && $request->filled('starts_at')) {
            try {
                $data = $request->validate(['starts_at' => 'required|date_format:Y-m-d\TH:i', 'minutes' => 'required|integer|min:15|max:480|multiple_of:15']);
                $tables = $this->reservations->available($data['starts_at'], (int) $data['minutes']);
            } catch (ValidationException $exception) {
                return redirect()->route('booking')->withErrors($exception->errors());
            }
        }

        return view('pages.booking', compact('enabled', 'tables'));
    }

    public function review(Request $request): View
    {
        $data = $request->validate(['billiard_table_id' => 'required|integer', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'minutes' => 'required|integer|min:15|max:480|multiple_of:15', 'customer_name' => 'required|string|max:150', 'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/'], 'consent' => 'accepted']);
        $quote = $this->reservations->quote((int) $data['billiard_table_id'], $data['starts_at'], (int) $data['minutes']);
        $draft = [...$data, ...$quote, 'submission_key' => (string) Str::uuid(), 'review_expires_at' => now()->addMinutes(15)->timestamp];
        $request->session()->put('booking_draft', $draft);

        return view('pages.booking-review', compact('draft'));
    }

    public function store(Request $request): RedirectResponse
    {
        $draft = $request->session()->get('booking_draft');
        abort_unless($draft && hash_equals($draft['submission_key'], (string) $request->input('submission_key')), 419);
        if ($draft['review_expires_at'] < now()->timestamp) {
            return redirect()->route('booking')->withErrors(['booking' => 'Your review expired. Please select the time again.']);
        }
        try {
            $record = $this->reservations->create($draft);
        } catch (ValidationException $exception) {
            return redirect()->route('booking', ['starts_at' => str_replace(' ', 'T', substr($draft['starts_at'], 0, 16)), 'minutes' => $draft['minutes']])->withErrors($exception->errors())->withInput(['customer_name' => $draft['customer_name'], 'phone' => $draft['phone']]);
        }
        $request->session()->put('booking_reference', $record->reference);

        return redirect()->route('booking.status')->with('booking_submitted', true);
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate(['reference' => 'required|string|size:32|alpha_num']);
        if (! Reservation::where('reference', $data['reference'])->exists()) {
            return back()->withErrors(['reference' => 'No booking found. Check your private code.']);
        }
        $request->session()->put('booking_reference', $data['reference']);

        return redirect()->route('booking.status');
    }

    public function status(Request $request): View|RedirectResponse
    {
        $this->reservations->expire();
        $record = Reservation::with('billiardTable')->where('reference', $request->session()->get('booking_reference', ''))->first();
        if (! $record) {
            return redirect()->route('booking');
        }

        return view('pages.booking-status',compact('record'));
    }
}
