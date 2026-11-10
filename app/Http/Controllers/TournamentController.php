<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\TournamentRegistration;
use App\Services\TournamentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TournamentController extends Controller
{
    public function show(Tournament $tournament, Request $request): View
    {
        abort_if($tournament->status === 'draft', 404);
        $key = (string) Str::uuid();
        $request->session()->put('tournament_draft', ['id' => $tournament->id, 'key' => $key, 'fee' => $tournament->entry_fee_minor]);

        return view('pages.tournament', compact('tournament', 'key'));
    }

    public function register(Tournament $tournament, Request $request, TournamentService $service): RedirectResponse
    {
        $request->validate(['consent' => 'accepted', 'submission_key' => 'required|uuid']);
        $draft = $request->session()->get('tournament_draft');
        abort_unless($draft && $draft['id'] === $tournament->id && hash_equals($draft['key'], $request->string('submission_key')->toString()), 419);
        $record = $service->register($tournament, [...$request->only('full_name', 'phone', 'submission_key'), 'quoted_fee_minor' => $draft['fee']]);
        $request->session()->put('tournament_reference', $record->reference);

        return redirect()->route('tournament.status');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate(['reference' => 'required|alpha_num|size:32']);
        if (! TournamentRegistration::where('reference', $data['reference'])->exists()) {
            return back()->withErrors(['reference' => 'No registration found. Check your code.']);
        }
        $request->session()->put('tournament_reference', $data['reference']);

        return redirect()->route('tournament.status');
    }

    public function status(Request $request): View|RedirectResponse
    {
        $record = TournamentRegistration::with('tournament')->where('reference', $request->session()->get('tournament_reference', ''))->first();

        return $record ? view('pages.tournament-status', compact('record')) : redirect()->route('tournaments');
    }
}
