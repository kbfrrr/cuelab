<x-layout :title="$tournament->title">
    <section class="container page-heading">
        <span class="eyebrow">Tournament</span>
        <h1>{{ $tournament->title }}</h1>
        <p>{{ $tournament->description }}</p>
    </section>
    <section class="container section content-grid">
        <div>
            <h2>Event details</h2>
            <p>{{ $tournament->starts_at->format('M j, Y g:i A') }} – {{ $tournament->ends_at->format('M j, Y g:i A') }} · Asia/Manila</p>
            <p>Registration deadline: {{ $tournament->registration_deadline?->format('M j, Y g:i A') }}</p>
            <p>Entry fee: PHP {{ number_format($tournament->entry_fee_minor / 100, 2) }}</p>
            <p>{{ max(0, $tournament->capacity - $tournament->registrations()->where('status', '!=', 'cancelled')->count()) }} seats remaining</p>
            <h2>Rules</h2>
            <p style="white-space:pre-line">{{ $tournament->rules }}</p>
            @if ($tournament->entry_fee_minor > 0)
                <p>After registering, pay through GCash and upload a transaction screenshot for staff to verify.</p>
            @else
                <p>No payment required.</p>
            @endif
        </div>
        <div class="detail-panel">
            @foreach ($errors->all() as $error)
                <p role="alert">{{ $error }}</p>
            @endforeach

            @if ($tournament->status !== 'published' || ! $tournament->registration_deadline?->isFuture() || ! $tournament->starts_at->isFuture())
                <h2>Registration closed</h2>
                <p>Event status: {{ $tournament->status }}</p>
            @elseif (! $canAcceptPayment)
                <h2>Registration paused</h2>
                <p>GCash payment details are unavailable. <a class="text-link" href="{{ route('contact') }}">Contact CueLab staff</a> for help.</p>
            @else
                <h2>Register</h2>
                <form class="booking-form" method="post" action="{{ route('tournament.register', $tournament) }}">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ $key }}">
                    <label>Full name<input name="full_name" value="{{ old('full_name') }}" maxlength="150" required></label>
                    <label>Phone<input name="phone" type="tel" value="{{ old('phone') }}" maxlength="32" required></label>
                    <label class="check-label"><input type="checkbox" name="consent" value="1" required>I agree to the event rules and <a href="{{ route('privacy') }}">privacy notice</a>.</label>
                    <button class="button">Register · PHP {{ number_format($tournament->entry_fee_minor / 100, 2) }}</button>
                </form>
            @endif
        </div>
    </section>
</x-layout>
