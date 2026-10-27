@extends('errors.layout')

@section('title', '500 - Internal Server Error')

@section('content')
    <div class="badge badge-500">500 &bull; Critical Server Exception</div>
    <div class="error-code">500</div>
    <div class="error-title">Internal Server Error</div>
    <div class="error-bangla">সার্ভার অভ্যন্তরীণ বিভ্রাট</div>
    <p class="error-desc">
        An unexpected server condition occurred while processing this request. Our technical staff has been notified to investigate.
    </p>

    <div class="btn-group">
        <a href="/" class="btn btn-primary">
            Return to Homepage
        </a>
        <button onclick="window.location.reload()" class="btn btn-secondary">
            Retry Request
        </button>
    </div>
@endsection
