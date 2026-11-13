@php
    $address = $settings['address'] ?? null;
    $phone = $settings['phone'] ?? null;
    $socialUrl = $settings['social_url'] ?? null;
    $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(filled($address) ? $address : 'The Cue Lab Billiards, Angeles, Pampanga');
    $dialNumber = is_string($phone) ? preg_replace('/[\s().-]+/', '', $phone) : '';
    $hasDialNumber = preg_match('/^\+?[0-9]{7,15}$/D', $dialNumber);
    $hasSocialLink = is_string($socialUrl) && filter_var($socialUrl, FILTER_VALIDATE_URL)
        && str_starts_with(strtolower($socialUrl), 'https://');
@endphp

<x-layout :information-unavailable="$informationUnavailable" title="Contact" :extra-fonts="['barlow-condensed']">
    <section class="container cue-contact" aria-labelledby="cue-contact-title">
        <div class="cue-contact-location">
            <div class="cue-contact-intro">
                <h1 id="cue-contact-title">Visit CueLab</h1>
                <address>{{ filled($address) ? $address : 'Address pending verification' }}</address>
                <a class="button cue-contact-directions" href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer">
                    Get directions <x-heroicon-o-arrow-up-right aria-hidden="true" />
                </a>
            </div>

            <a class="cue-contact-map" href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer"
                aria-label="Open the CueLab location in Google Maps">
                <img src="{{ asset('images/cuelab-map.webp') }}" width="1915" height="892"
                    alt="Map of CueLab Billiards on MacArthur Highway in Angeles, Pampanga">
            </a>
        </div>

        <dl class="cue-contact-details">
            <div>
                <dt>Call the venue</dt>
                <dd>
                    @if ($hasDialNumber)
                        <a href="tel:{{ $dialNumber }}">{{ $phone }}</a>
                    @else
                        {{ filled($phone) ? $phone : 'Phone number pending verification' }}
                    @endif
                </dd>
            </div>
            <div>
                <dt>Social media</dt>
                <dd>
                    @if ($hasSocialLink)
                        <a href="{{ $socialUrl }}" target="_blank" rel="noopener noreferrer">Visit our social page</a>
                    @else
                        Official social link pending verification
                    @endif
                </dd>
            </div>
        </dl>
    </section>
</x-layout>
