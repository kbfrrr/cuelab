<x-layout title="Tournaments" :extra-fonts="['barlow-condensed']" :information-unavailable="$informationUnavailable">
    <div class="container cue-tournaments">
        <section class="cue-tournaments-hero" aria-labelledby="cue-tournaments-title">
            <div class="cue-tournaments-intro">
                <h1 id="cue-tournaments-title">CueLab <em>Tournaments</em></h1>
                <p>Find your next billiards tournament in Angeles and see how to enter.</p>
                <a class="button cue-tournaments-primary" href="{{ $tournaments->isEmpty() ? '#registration-lookup' : '#tournament-schedule' }}">
                    {{ $tournaments->isEmpty() ? 'Check a registration' : 'View the schedule' }}
                </a>
            </div>
            <img class="cue-tournaments-photo" src="{{ asset('images/cuelab-trophies.webp') }}" width="1320" height="1320"
                alt="Champion and first runner-up trophies from a CueLab 10-ball tournament.">
        </section>

        <div class="cue-tournaments-grid">
            <section id="tournament-schedule" aria-labelledby="tournament-schedule-title">
                <h2 id="tournament-schedule-title">Tournament schedule</h2>

                @if ($tournaments->isNotEmpty())
                    <p class="cue-tournaments-timezone">All times are in Philippine time (PHT).</p>
                    <ul class="cue-tournaments-list" role="list">
                        @foreach ($tournaments as $event)
                            <li class="cue-tournaments-event">
                                <time class="cue-tournaments-date" datetime="{{ $event->starts_at->toIso8601String() }}">
                                    <span>{{ $event->starts_at->format('M') }}</span>
                                    <strong>{{ $event->starts_at->format('j') }}</strong>
                                    <span>{{ $event->starts_at->format('Y') }}</span>
                                </time>
                                <div class="cue-tournaments-event-info">
                                    <h3>{{ $event->title }}</h3>
                                    <p>{{ $event->starts_at->format('g:i A') }} <span aria-hidden="true">·</span> Entry fee ₱{{ number_format($event->entry_fee_minor / 100, 2) }}</p>
                                </div>
                                <a class="cue-tournaments-event-link" href="{{ route('tournament.show', $event) }}"
                                    aria-label="View details for {{ $event->title }}">
                                    View details <span aria-hidden="true">↗</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="cue-tournaments-empty">
                        {{ $informationUnavailable ? 'The schedule is temporarily unavailable.' : 'No tournaments are scheduled right now.' }}
                    </p>
                @endif
            </section>

            <section class="cue-tournaments-lookup" id="registration-lookup" aria-labelledby="registration-lookup-title">
                <h2 id="registration-lookup-title">Check your registration</h2>
                <p id="tournament-reference-hint">Enter the 32-character reference code you received when you registered.</p>
                <form method="post" action="{{ route('tournament.lookup') }}">
                    @csrf
                    <label for="tournament-reference">Private reference code</label>
                    <input id="tournament-reference" name="reference" type="text" minlength="32" maxlength="32"
                        required autocomplete="off" autocapitalize="none" spellcheck="false"
                        aria-describedby="tournament-reference-hint{{ $errors->has('reference') ? ' tournament-reference-error' : '' }}"
                        @error('reference') aria-invalid="true" autofocus @enderror>
                    @error('reference')
                        <p class="cue-tournaments-error" id="tournament-reference-error" role="alert">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="button cue-tournaments-primary">Check registration</button>
                </form>
            </section>
        </div>
    </div>
</x-layout>
