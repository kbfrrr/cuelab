@props(['title' => 'Welcome', 'informationUnavailable' => false, 'visitUrl' => null, 'visitLabel' => 'Plan a visit'])
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · CueLab</title>
    <meta name="description" content="Discover CueLab. Explore billiards, venue information, and upcoming booking and tournament services.">
    <meta name="theme-color" content="#fbf7f3">
    <script>try { document.documentElement.dataset.theme = localStorage.getItem('cuelab-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch(e) {}</script>
    @fonts
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="cue-public-shell" x-data="siteShell" @keydown.escape.window="menuOpen = false">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ route('home') }}" aria-label="CueLab home"><span class="cue-wordmark"><img src="{{ asset('cuelab-logo.jpg') }}" width="960" height="960" alt="The Cue Lab Billiards"></span></a>
        <nav class="desktop-nav" aria-label="Main navigation">
            @foreach (['home'=>'Home','about'=>'About','rates'=>'Rates & services','tournaments'=>'Tournaments','contact'=>'Contact'] as $name=>$label)
                <a href="{{ route($name) }}" @if(request()->routeIs($name)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <a class="staff-login-link" href="{{ route('filament.admin.auth.login') }}">Staff login</a>
        </nav>
        <div class="header-actions">
            <button class="icon-button" @click="toggleTheme" :aria-label="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"><span x-text="theme === 'dark' ? '☀' : '◐'" aria-hidden="true">◐</span></button>
            <a class="button button-small desktop-book" href="{{ $visitUrl ?? route('booking') }}">{{ $visitLabel }} <span aria-hidden="true">↗</span></a>
            <button class="icon-button menu-button" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen" aria-controls="mobile-nav" aria-label="Toggle navigation">☰</button>
        </div>
    </div>
    <nav id="mobile-nav" class="mobile-nav container" x-show="menuOpen" x-cloak aria-label="Mobile navigation">
        @foreach (['home'=>'Home','about'=>'About','rates'=>'Rates & services','tournaments'=>'Tournaments','contact'=>'Contact','booking'=>'Plan a visit'] as $name=>$label)
            <a href="{{ $name === 'booking' && $visitUrl ? $visitUrl : route($name) }}" @click="menuOpen = false" @if(request()->routeIs($name)) aria-current="page" @endif>{{ $name === 'booking' ? $visitLabel : $label }}</a>
        @endforeach
        <a class="staff-login-link" href="{{ route('filament.admin.auth.login') }}" @click="menuOpen = false">Staff login</a>
    </nav>
</header>
@if($informationUnavailable)<div class="service-notice container" role="status">Live venue information is temporarily unavailable. You can still explore the site.</div>@endif
<main id="main">{{ $slot }}</main>
<footer class="site-footer">
    <div class="container footer-top"><div><a class="brand" href="{{ route('home') }}"><span class="brand-ball">8</span> CueLab.</a><p>A place for the game. A reason to get together.</p></div><a class="text-link" href="{{ route('contact') }}">Get in touch <span aria-hidden="true">↗</span></a></div>
    <div class="container footer-bottom"><span>© {{ date('Y') }} CueLab</span><span>CueLab Billiards · Angeles, Pampanga</span><a href="{{ route('privacy') }}">Privacy notice</a></div>
</footer>
<x-cuechat />
</body></html>
