<x-layout title="Book a table">
<section class="container page-heading"><span class="eyebrow">Your next game</span><h1>Make room<br>for a <em>game.</em></h1><p>Choose a time, review the price, and request staff approval. All times are Asia/Manila.</p></section>
<section class="container section content-grid"><div>
@if($errors->any())<div class="service-notice" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(!$enabled)<span class="pending-label">Booking not yet open</span><h2>A few details first.</h2><p class="lead">No reservations are being accepted until venue-approved rates, opening hours, and tables are ready.</p><a class="text-link" href="{{ route('rates') }}">Check venue details ↗</a>
@else
<h2>1. Find a table</h2><form method="get" action="{{ route('booking') }}" class="booking-form"><label>Start time<input type="datetime-local" name="starts_at" value="{{ request('starts_at') }}" required></label><label>Duration<select name="minutes">@foreach([30,60,90,120,180,240] as $minutes)<option value="{{ $minutes }}" @selected((int)request('minutes',60)===$minutes)>{{ $minutes }} minutes</option>@endforeach</select></label><button class="button">Check availability →</button></form>
@if(request()->filled('starts_at'))
@if($tables->isEmpty())<p class="service-notice" role="status">No tables are available for this time. Try another schedule.</p>
@else<h2 class="booking-heading">2. Your details</h2><form method="post" action="{{ route('booking.review') }}" class="booking-form">@csrf
<input type="hidden" name="starts_at" value="{{ request('starts_at') }}"><input type="hidden" name="minutes" value="{{ request('minutes') }}">
<label>Available table<select name="billiard_table_id" required>@foreach($tables as $table)<option value="{{ $table->id }}">{{ $table->name }} · PHP {{ number_format($table->serviceRate->amount_minor/100,2) }} / hour</option>@endforeach</select></label>
<label>Full name<input name="customer_name" autocomplete="name" maxlength="150" value="{{ old('customer_name') }}" required></label><label>Phone number<input type="tel" name="phone" autocomplete="tel" maxlength="32" value="{{ old('phone') }}" required></label>
<label class="check-label"><input type="checkbox" name="consent" value="1" required> I have read the <a href="{{ route('privacy') }}">privacy notice</a> and agree to use my details for this booking request.</label>
<button class="button">Review request →</button></form>@endif @endif
@endif</div>
<aside class="detail-panel"><span class="eyebrow">Already sent a request?</span><h3>Check your booking</h3><p>Keep your code private. Anyone with it can see your booking status.</p><form method="post" action="{{ route('booking.lookup') }}" class="booking-form">@csrf<label>Private reference code<input name="reference" minlength="32" maxlength="32" autocomplete="off" required></label><button class="button">Check status →</button></form><p class="muted">Requests need staff approval. Pending holds expire automatically, freeing the slot.</p></aside></section></x-layout>
