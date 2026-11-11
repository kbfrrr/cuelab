<x-layout title="Booking status">
    <section class="container page-heading">
        <span class="eyebrow">Your request</span>
        <h1>{{ match ($record->status) {
            'pending' => 'Awaiting approval.',
            'confirmed' => 'You’re booked.',
            'expired' => 'Your hold expired.',
            'rejected' => 'Request declined.',
            'cancelled' => 'Booking cancelled.',
            'no_show' => 'Your table was released.',
            default => 'Booking status.',
        } }}</h1>
        <p>Refresh this page for the latest booking status.</p>
    </section>

    <section class="container section content-grid">
        <div class="detail-panel">
            <h2>{{ $record->billiardTable->name }}</h2>
            <dl>
                <div><dt>Private reference · save this code</dt><dd class="reference-code">{{ $record->reference }}</dd></div>
                <div><dt>Schedule · Asia/Manila</dt><dd>{{ $record->starts_at->format('M j, Y g:i A') }} – {{ $record->ends_at->format('M j, Y g:i A') }}</dd></div>
                @if ($record->status === 'confirmed' && $record->session_starts_at)
                    <div><dt>Session starts</dt><dd>{{ $record->session_starts_at->format('M j, Y g:i A') }}</dd></div>
                    <div><dt>Session ends</dt><dd>{{ $record->session_ends_at->format('M j, Y g:i A') }}</dd></div>
                    <div><dt>Session total</dt><dd>PHP {{ number_format($record->session_total_minor / 100, 2) }}</dd></div>
                    @if ($record->late_contact_outcome === 'coming' && $record->replyDeadline()?->gt(now()))
                        <div><dt>Arrive by</dt><dd>{{ $record->replyDeadline()->format('M j, Y g:i A') }}</dd></div>
                    @endif
                @else
                    <div><dt>Quoted total</dt><dd>PHP {{ number_format($record->quoted_total_minor / 100, 2) }}</dd></div>
                @endif
                @if ($record->status === 'pending')
                    <div><dt>Held until</dt><dd>{{ $record->hold_expires_at?->format('M j, Y g:i A') }}</dd></div>
                @endif
            </dl>
            <a class="button" href="{{ route('booking.status') }}">Refresh status ↻</a>
        </div>
        <div>
            <h2>Keep your code private.</h2>
            <p class="lead">Only confirmed requests hold a table beyond the approval period.</p>
            <div class="button-row">
                <a class="text-link" href="{{ route('booking') }}">Make another request →</a>
                <a class="text-link" href="{{ route('contact') }}">Contact the venue →</a>
            </div>
        </div>
    </section>
</x-layout>
