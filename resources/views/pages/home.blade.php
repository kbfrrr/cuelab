@php
    $address = $settings['address'] ?? null;
    $tableRates = $rates->where('unit', 'table/hour')->where('currency', 'PHP');
    $hourlyRate = $tableRates->min('amount_minor');
    $week = $hours->keyBy('day_of_week');
    $openDays = $hours->reject(fn ($hour) => $hour->is_closed);
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $starts = $openDays->filter(fn ($hour) => $week->get(($hour->day_of_week + 6) % 7)?->is_closed);
    $sameHours = $openDays->isNotEmpty() && $openDays->map(fn ($hour) => [$hour->opens_at, $hour->closes_at])->unique()->count() === 1;
    $simpleSchedule = $week->count() === 7 && $sameHours && ($starts->count() === 1 || $openDays->count() === 7);
    $firstDay = $starts->first()?->day_of_week;
    $scheduleLabel = $firstDay === null ? 'Every day' : ($openDays->count() === 1 ? $dayNames[$firstDay] : $dayNames[$firstDay].'–'.$dayNames[($firstDay + $openDays->count() - 1) % 7]);
    $visitUrl = $bookingReady ? route('booking') : '#visit';
    $visitLabel = $bookingReady ? 'Request a table' : 'Plan a visit';
    $heroSlides = [
        ['file' => 'cuelab-hall.webp', 'width' => 720, 'height' => 960, 'position' => 'center 62%', 'alt' => 'The CueLab hall with blue-felt billiard tables under square LED ceiling lights.'],
        ['file' => 'cuelab-tables.webp', 'width' => 1440, 'height' => 1920, 'position' => 'center 55%', 'alt' => 'Guests playing at a row of Star Billiards tables with blue felt.'],
        ['file' => 'cuelab-trophies.webp', 'width' => 1320, 'height' => 1320, 'position' => 'center 60%', 'alt' => 'Champion and first runner-up trophies from a CueLab 10-ball tournament on a blue table.'],
        ['file' => 'cuelab-open.webp', 'width' => 1500, 'height' => 2000, 'position' => 'center', 'fit' => 'contain', 'alt' => 'A “We’re open, 4 PM to 2 AM” poster over a Maxima 8 Diamond table branded The Cue Lab Billiards.'],
    ];
@endphp

<x-layout :information-unavailable="$informationUnavailable" title="Billiards in Angeles" :visit-url="$visitUrl" :visit-label="$visitLabel">
    <section class="container cue-home-hero" aria-labelledby="cue-home-title">
        <div class="cue-intro">
            <p class="cue-kicker"><span></span> CueLab Billiards <span class="cue-kicker-divider"></span> Angeles, Pampanga</p>
            <h1 id="cue-home-title">Rack ’em.<br><span>Stay awhile.</span></h1>
            <p class="cue-intro-copy">An evening at the table, a few good games, and a reason to get together.</p>
            <div class="cue-hero-actions">
                <a class="button cue-primary" href="{{ $visitUrl }}">{{ $visitLabel }} <x-heroicon-o-arrow-up-right aria-hidden="true" /></a>
                <a class="cue-text-link" href="{{ route('rates') }}">Rates & hours <x-heroicon-o-arrow-right aria-hidden="true" /></a>
            </div>
            @if($bookingReady)
                <p class="cue-booking-note">Choose a time and send a request for staff approval.</p>
            @endif
        </div>
        <figure class="cue-break-art cue-carousel" x-data="heroCarousel({{ count($heroSlides) }})" aria-roledescription="carousel" aria-label="Photos of The Cue Lab Billiards"
            @mouseenter="pause()" @mouseleave="resume()" @focusin="pause()" @focusout="resume()"
            @touchstart.passive="touchStart($event)" @touchend="touchEnd($event)" @keydown.left="prev()" @keydown.right="next()">
            <div class="cue-slides" aria-live="off" :aria-live="paused ? 'polite' : 'off'">
                @foreach ($heroSlides as $index => $slide)
                    <img @class(['cue-slide', 'is-active' => $index === 0]) :class="{ 'is-active': current === {{ $index }} }"
                        src="{{ asset('images/'.$slide['file']) }}" width="{{ $slide['width'] }}" height="{{ $slide['height'] }}"
                        style="object-position: {{ $slide['position'] }}; object-fit: {{ $slide['fit'] ?? 'cover' }}" alt="{{ $slide['alt'] }}"
                        aria-roledescription="slide" aria-label="{{ $index + 1 }} of {{ count($heroSlides) }}"
                        @if ($index === 0) fetchpriority="high" @else loading="lazy" aria-hidden="true" @endif
                        :aria-hidden="current !== {{ $index }}">
                @endforeach
            </div>
            <figcaption>
                <span>Chalk. Aim. Dominate.</span>
                <span class="cue-carousel-controls">
                    <button type="button" class="cue-carousel-arrow" @click="prev()" aria-label="Previous photo"><x-heroicon-o-chevron-left aria-hidden="true" /></button>
                    <span class="cue-carousel-dots">
                        @foreach ($heroSlides as $index => $slide)
                            <button type="button" @class(['cue-carousel-dot', 'is-active' => $index === 0]) :class="{ 'is-active': current === {{ $index }} }"
                                @click="go({{ $index }})" aria-label="Show photo {{ $index + 1 }}" :aria-current="current === {{ $index }}"></button>
                        @endforeach
                    </span>
                    <button type="button" class="cue-carousel-arrow" @click="next()" aria-label="Next photo"><x-heroicon-o-chevron-right aria-hidden="true" /></button>
                </span>
            </figcaption>
        </figure>
    </section>

    <section id="visit" class="container cue-visit" aria-labelledby="cue-visit-title" data-cue-chapter>
        <div class="cue-section-head" data-cue-lead>
            <h2 id="cue-visit-title">Plan your visit.</h2>
            <p>Rates, opening hours, and where to find us.</p>
        </div>
        <div class="cue-visit-grid">
            <article class="cue-tile cue-rate" data-cue-support>
                <div class="cue-tile-top"><h3>Table rental</h3><x-heroicon-o-currency-dollar aria-hidden="true" /></div>
                @if($hourlyRate !== null)
                    @if($tableRates->pluck('amount_minor')->unique()->count() > 1)<span class="cue-tile-meta">From</span>@endif
                    <p class="cue-price">₱{{ number_format($hourlyRate / 100, $hourlyRate % 100 === 0 ? 0 : 2) }}</p><p class="cue-tile-meta">per table / hour</p>
                @else
                    <p class="cue-tile-copy">Check back for the confirmed rate.</p>
                @endif
                <a class="cue-text-link" href="{{ route('rates') }}">See rates <x-heroicon-o-arrow-right aria-hidden="true" /></a>
            </article>
            <article class="cue-tile cue-hours" data-cue-support>
                <div class="cue-tile-top"><h3>Opening hours</h3><x-heroicon-o-clock aria-hidden="true" /></div>
                @if($simpleSchedule)
                    @php($hoursSample = $openDays->first())
                    <p class="cue-tile-copy">{{ $scheduleLabel }}</p>
                    <p class="cue-tile-meta">{{ date('g:i A', strtotime($hoursSample->opens_at)) }}–{{ date('g:i A', strtotime($hoursSample->closes_at)) }}{{ $hoursSample->closes_at <= $hoursSample->opens_at ? ' the next day' : '' }}</p>
                @else
                    <p class="cue-tile-copy">{{ $hours->isEmpty() ? 'Hours pending confirmation' : 'See the weekly schedule' }}</p>
                @endif
                <a class="cue-text-link" href="{{ route('rates') }}#hours">Weekly schedule <x-heroicon-o-arrow-right aria-hidden="true" /></a>
            </article>
            <article class="cue-tile cue-location" data-cue-support>
                <div class="cue-tile-top"><h3>Find CueLab</h3><x-heroicon-o-map-pin aria-hidden="true" /></div>
                <address>{{ $address ?? 'Location pending confirmation.' }}</address>
                @if($address)
                    <a class="cue-text-link" href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($address) }}" target="_blank" rel="noopener noreferrer">Get directions <x-heroicon-o-arrow-up-right aria-hidden="true" /></a>
                @else
                    <a class="cue-text-link" href="{{ route('contact') }}">Contact CueLab <x-heroicon-o-arrow-right aria-hidden="true" /></a>
                @endif
            </article>
        </div>
        <p class="lead">{{ config('cuelab.venue_policy') }}
            <a class="cue-text-link" href="{{ route('rates') }}#house-rules">House rules <x-heroicon-o-arrow-right aria-hidden="true" /></a>
        </p>
    </section>

    <section class="container cue-explore" aria-labelledby="cue-explore-title" data-cue-chapter>
        <div class="cue-section-head" data-cue-lead><h2 id="cue-explore-title">More ways to play.</h2></div>
        <div class="cue-explore-grid">
            <article class="cue-tile cue-event" data-cue-support>
                <div class="cue-tile-top"><h3>Tournaments</h3><x-heroicon-o-trophy aria-hidden="true" /></div>
                @if($tournaments->isNotEmpty())
                    <p class="cue-event-title">{{ $tournaments->first()->title }}</p>
                    <p class="cue-tile-meta">{{ $tournaments->first()->starts_at->format('M j · g:i A') }} · Asia/Manila</p>
                @else
                    <p class="cue-event-title">A little friendly competition.</p>
                    <p class="cue-tile-meta">Check the event schedule for upcoming matches.</p>
                @endif
                <a class="cue-text-link" href="{{ route('tournaments') }}">Explore tournaments <x-heroicon-o-arrow-right aria-hidden="true" /></a>
            </article>
            <article class="cue-tile cue-questions" data-cue-support>
                <div class="cue-tile-top"><h3>Before you play</h3><x-heroicon-o-chat-bubble-left-right aria-hidden="true" /></div>
                <p class="cue-tile-copy">Got a question?</p>
                <p class="cue-tile-meta">Get in touch with CueLab or check an existing booking.</p>
                <div class="cue-tile-actions">
                    <a class="cue-text-link" href="{{ route('contact') }}">Contact CueLab <x-heroicon-o-arrow-right aria-hidden="true" /></a>
                    <a class="cue-text-link" href="{{ route('booking') }}">Booking status <x-heroicon-o-arrow-right aria-hidden="true" /></a>
                </div>
            </article>
        </div>
    </section>
</x-layout>
