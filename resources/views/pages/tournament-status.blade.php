@php
    $paymentStatus = match (true) {
        $record->status === 'cancelled' => 'Cancelled',
        $record->status === 'paid' => 'Paid',
        $record->tournament->status === 'cancelled' => 'Event cancelled',
        $record->quoted_fee_minor === 0 => 'No payment required',
        $proof?->status === 'pending' => 'Under review',
        $proof?->status === 'rejected' => 'Proof rejected',
        default => 'Awaiting proof',
    };
@endphp

<x-layout title="Registration status">
    <section class="container page-heading">
        <span class="eyebrow">Your registration</span>
        <h1>{{ $paymentStatus }}</h1>
        <p>{{ $record->tournament->title }} · Event {{ $record->tournament->status }}</p>
    </section>
    <section class="container section content-grid">
        <div class="detail-panel">
            <h2>Your private code</h2>
            <p class="reference-code">{{ $record->reference }}</p>
            <dl>
                <div>
                    <dt>Quoted entry fee</dt>
                    <dd>PHP {{ number_format($record->quoted_fee_minor / 100, 2) }}</dd>
                </div>
                <div>
                    <dt>Tournament starts · Asia/Manila</dt>
                    <dd>{{ $record->tournament->starts_at->format('M j, Y g:i A') }}</dd>
                </div>
            </dl>
            <p>Keep this code private. <a class="text-link" href="{{ route('contact') }}">Contact CueLab staff</a> for help with payment or cancellation.</p>
            <div class="button-row"><a class="button" href="{{ route('tournament.status') }}">Refresh status</a></div>
        </div>

        @if ($record->tournament->status === 'cancelled' || $record->status === 'cancelled')
            <p>If you already paid, contact CueLab staff about your payment.</p>
        @elseif ($record->status === 'unpaid' && $record->quoted_fee_minor > 0)
            <div class="detail-panel">
                <h2>GCash payment</h2>

                @if ($proof?->status === 'rejected')
                    <p class="service-notice">Staff feedback: {{ $proof->rejection_reason }}</p>
                @endif

                @if ($canSubmit)
                    @if ($recipient)
                        <dl>
                            <div><dt>Recipient</dt><dd>{{ $recipient['name'] }}</dd></div>
                            <div><dt>GCash mobile number</dt><dd>{{ $recipient['number'] }}</dd></div>
                        </dl>
                        <p>Send the quoted entry fee to this account. Check the recipient in GCash before sending.</p>
                    @else
                        <p>GCash payment details are unavailable. Contact CueLab staff before sending payment. If you’ve already paid, you can still submit proof below.</p>
                    @endif

                    <form class="booking-form" method="post" action="{{ route('tournament.proof.store') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="registration_id" value="{{ $record->id }}">
                        <fieldset>
                            <legend>Submit payment proof</legend>
                            @error('registration_id')
                                <p class="service-notice" role="alert">{{ $message }}</p>
                            @enderror
                            <label for="transaction-reference">GCash transaction reference</label>
                            <input id="transaction-reference" name="transaction_reference" value="{{ old('transaction_reference') }}" maxlength="80"
                                autocomplete="off" spellcheck="false" required
                                @error('transaction_reference') aria-invalid="true" aria-describedby="transaction-reference-error" @enderror>
                            @error('transaction_reference')
                                <p class="service-notice" id="transaction-reference-error" role="alert">{{ $message }}</p>
                            @enderror

                            <label for="payment-screenshot">Payment screenshot</label>
                            <input id="payment-screenshot" type="file" name="screenshot" accept="image/jpeg,image/png" required
                                aria-describedby="screenshot-hint{{ $errors->has('screenshot') ? ' screenshot-error' : '' }}"
                                @error('screenshot') aria-invalid="true" @enderror>
                            <p id="screenshot-hint">JPEG or PNG, up to 2 MB. Include the amount, recipient, and transaction reference.</p>
                            @error('screenshot')
                                <p class="service-notice" id="screenshot-error" role="alert">{{ $message }}</p>
                            @enderror
                        </fieldset>
                        <div class="button-row"><button type="submit" class="button">Submit proof</button></div>
                    </form>
                @elseif ($proof?->status === 'pending')
                    <p>CueLab staff will compare your submission with the GCash transfer. Refresh this page to check their decision.</p>
                @else
                    <p>Proof submissions are closed. Contact CueLab staff for help with your payment.</p>
                @endif
                @if (! $canSubmit)
                    @error('screenshot')
                        <p class="service-notice" role="alert">{{ $message }}</p>
                    @enderror
                @endif
            </div>
        @endif
    </section>
</x-layout>
