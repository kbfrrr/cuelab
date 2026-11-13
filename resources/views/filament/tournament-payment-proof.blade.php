<div class="cl-payment-proof">
    @if ($proof)
        <dl>
            <div><dt>GCash transaction reference</dt><dd>{{ $proof->transaction_reference }}</dd></div>
            <div><dt>Quoted entry fee</dt><dd>₱{{ number_format($record->quoted_fee_minor / 100, 2) }}</dd></div>
        </dl>
        @if ($record->tournament->ends_at->gt(now()->subDays(30)))
            <p>Compare the transaction reference and amount with the transfer in CueLab’s GCash account.</p>
            @php($imageUrl = route('tournament.proof.show', ['registration' => $record->id, 'version' => $proof->version]))
            <figure>
                <img src="{{ $imageUrl }}" alt="Player’s GCash payment screenshot">
                <figcaption>
                    <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">Open full-size screenshot (new tab)</a>
                </figcaption>
            </figure>
        @else
            <p>The screenshot has expired. Reject the proof and tell the player how to contact staff.</p>
        @endif
    @else
        <p>This submission has changed. Close the review and open it again to see the latest proof.</p>
    @endif
</div>
