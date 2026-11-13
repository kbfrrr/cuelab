<x-layout :information-unavailable="$informationUnavailable" title="Contact">
    <section class="container page-heading">
        <span class="eyebrow">Let’s connect</span>
        <h1>Talk to <em>CueLab.</em></h1>
        <p>Have a question before you visit? This is where verified venue contact details will be available.</p>
    </section>

    <section class="container section content-grid">
        <div class="editorial">
            <h2>Find your way here.</h2>
            <figure class="cue-map">
                <a href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($settings['address'] ?? 'The Cue Lab Billiards, Angeles, Pampanga') }}" target="_blank" rel="noopener noreferrer">
                    <img src="{{ asset('images/cuelab-map.webp') }}" width="1915" height="892" loading="lazy" alt="Map showing The Cue Lab Billiards on MacArthur Highway, Barangay Ninoy Aquino, Angeles, Pampanga.">
                    <figcaption><span>The Cue Lab Billiards</span><span>Open in Maps ↗</span></figcaption>
                </a>
            </figure>
        </div>
        <dl class="contact-details">
            <div>
                <dt>Visit us</dt>
                <dd>{{ $settings['address'] ?? 'Address pending verification' }}</dd>
            </div>
            <div>
                <dt>Call the venue</dt>
                <dd>{{ $settings['phone'] ?? 'Phone number pending verification' }}</dd>
            </div>
            <div>
                <dt>Social media</dt>
                <dd>
                    @if (!empty($settings['social_url']) && preg_match('/^https:\/\//i', $settings['social_url']))
                        <a class="text-link" href="{{ $settings['social_url'] }}" rel="noopener noreferrer" target="_blank">Visit our social page ↗</a>
                    @else
                        Official social link pending verification
                    @endif
                </dd>
            </div>
        </dl>
    </section>
</x-layout>
