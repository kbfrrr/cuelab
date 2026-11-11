<x-layout title="Review your booking">
    <section class="container page-heading">
        <span class="eyebrow">One last check</span>
        <h1>Review your game.</h1>
        <p>Your slot is checked again when you submit. This review expires in 15 minutes.</p>
    </section>
    <section class="container section content-grid">
        <div class="detail-panel">
            <h2>{{ $draft['table_name'] }}</h2>
            <dl>
                <div><dt>Schedule · Asia/Manila</dt><dd>{{ $draft['starts_at'] }} – {{ $draft['ends_at'] }}</dd></div>
                <div><dt>Guest</dt><dd>{{ $draft['customer_name'] }} · {{ $draft['phone'] }}</dd></div>
                <div><dt>Hourly rate</dt><dd>PHP {{ number_format($draft['hourly_rate']/100,2) }}</dd></div>
                <div><dt>Total for {{ $draft['minutes'] }} minutes</dt><dd>PHP {{ number_format($draft['total']/100,2) }}</dd></div>
            </dl>
            <p>No deposit is collected online. Staff approval is required.</p>
            <form method="post" action="{{ route('booking.store') }}" class="booking-form" x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                <input type="hidden" name="submission_key" value="{{ $draft['submission_key'] }}">
                <button class="button" :disabled="submitting" x-text="submitting ? 'Sending…' : 'Submit booking request'">Submit booking request</button>
            </form>
            <a class="text-link" href="{{ route('booking', ['starts_at'=>str_replace(' ','T',substr($draft['starts_at'],0,16)),'minutes'=>$draft['minutes']]) }}">← Change details</a>
        </div>
        <div>
            <h2>What happens next?</h2>
            <p class="lead">You’ll receive a private code to check the request. The table is held temporarily while staff review it. If the hold expires before approval, you can make a new request.</p>
            <h3>Running late?</h3>
            <p>Confirmed bookings have a 15-minute grace period from the scheduled start. If a walk-in needs your table after that, staff may call you. If you confirm you’re coming, your session starts then and you have five more minutes to arrive. Staff may release the table if you decline, miss that arrival deadline, or leave their call unanswered for five minutes.</p>
            <p>If a later booking or closing time shortens your session, staff will confirm the duration and price with you. Each started 15-minute block is included in the quote.</p>
        </div>
    </section>
</x-layout>
