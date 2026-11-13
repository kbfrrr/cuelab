<div class="space-y-3">
    @if ($proof)
        <dl>
            <div><dt>GCash transaction reference</dt><dd>{{ $proof->transaction_reference }}</dd></div>
            <div><dt>Quoted entry fee</dt><dd>₱{{ number_format($record->quoted_fee_minor / 100, 2) }}</dd></div>
        </dl>
        @if ($record->tournament->ends_at->gt(now()->subDays(30)))
            <p>Compare the transaction reference and amount with the transfer in CueLab’s GCash account.</p>
            @php($imageUrl = route('tournament.proof.show', ['registration' => $record->id, 'version' => $proof->version]))
            <a class="underline" href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">Open screenshot at full size</a>
            <img class="max-h-96 w-auto max-w-full rounded-lg" src="{{ $imageUrl }}" alt="Player’s GCash payment screenshot">
        @else
            <p>The screenshot has expired. Reject the proof and tell the player how to contact staff.</p>
        @endif
    @else
        <p>This submission has changed. Close the review and open it again to see the latest proof.</p>
    @endif
</div>
