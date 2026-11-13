<?php

namespace App\Http\Controllers;

use App\Models\TournamentRegistration;
use App\Services\TournamentPaymentProofService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\Response;

class TournamentPaymentProofController extends Controller
{
    public function store(Request $request, TournamentPaymentProofService $paymentProofs): RedirectResponse
    {
        $reference = $request->session()->get('tournament_reference');
        if (! is_string($reference) || $reference === '') {
            return redirect()->route('tournaments');
        }

        $registration = TournamentRegistration::where('reference', $reference)->first();
        if (! $registration) {
            return redirect()->route('tournaments');
        }

        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'transaction_reference' => ['required', 'string', 'max:80'],
            'screenshot' => ['required', File::types(['jpeg', 'png'])->max(2048)],
        ]);

        if ((int) $data['registration_id'] !== $registration->id) {
            return redirect()->route('tournament.status')->withErrors([
                'registration_id' => 'Your active registration changed. Check this registration before submitting proof.',
            ]);
        }

        try {
            $paymentProofs->submit($registration, $data['transaction_reference'], $data['screenshot']);
        } catch (QueryException $exception) {
            // Query exceptions can include the screenshot and reference in their SQL bindings.
            logger()->error('Tournament payment proof upload could not be saved.', ['sqlstate' => $exception->getCode()]);

            return redirect()->route('tournament.status')->withErrors([
                'screenshot' => 'We could not save your proof. Try uploading it again.',
            ]);
        }

        return redirect()->route('tournament.status');
    }

    public function show(Request $request, int $registration, string $version, TournamentPaymentProofService $paymentProofs): Response
    {
        abort_unless($request->user()?->is_staff, 403);

        $proof = $paymentProofs->proofs()->where('tournament_registration_id', $registration)
            ->where('version', $version)->whereNotNull('image_base64')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('tournament_registrations')
                    ->join('tournaments', 'tournaments.id', '=', 'tournament_registrations.tournament_id')
                    ->whereColumn('tournament_registrations.id', 'tournament_payment_proofs.tournament_registration_id')
                    ->where('tournaments.ends_at', '>', now()->subDays(30));
            })
            ->first(['mime_type', 'image_base64']);

        abort_unless($proof, 404);
        $image = base64_decode($proof->image_base64, true);
        abort_if($image === false, 404);

        return response($image, 200, [
            'Content-Type' => $proof->mime_type,
            'Content-Disposition' => 'inline; filename="payment-proof.'.($proof->mime_type === 'image/png' ? 'png' : 'jpg').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
