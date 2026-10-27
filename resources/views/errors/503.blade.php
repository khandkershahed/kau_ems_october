@extends('errors.layout')

@section('title', '503 - Service Unavailable')

@section('content')
<div class="badge badge-503">503 &bull; System Maintenance</div>
<div class="error-code">503</div>
<div class="error-title">Under Maintenance</div>
<div class="error-bangla">সিস্টেম রক্ষণাবেক্ষণ চলছে</div>
<p class="error-desc">
    The Education Management System is temporarily unavailable for scheduled maintenance or database optimization. Please check back shortly.
</p>

<div class="btn-group">
    <button onclick="window.location.reload()" class="btn btn-primary">
        Check Again
    </button>
</div>
@endsection