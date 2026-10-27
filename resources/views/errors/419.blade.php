@extends('errors.layout')

@section('title', '419 - Session Expired')

@section('content')
    <div class="badge badge-403">419 &bull; Security Token Timeout</div>
    <div class="error-code">419</div>
    <div class="error-title">Session Expired</div>
    <div class="error-bangla">নিরাপত্তা সেশনের মেয়াদ শেষ</div>
    <p class="error-desc">
        Your CSRF security token has expired due to page inactivity. Please refresh the page or sign in again to continue safely.
    </p>

    <div class="btn-group">
        <button onclick="window.location.reload()" class="btn btn-primary">
            Reload Page
        </button>
        <a href="/login" class="btn btn-secondary">
            Sign In Again
        </a>
    </div>
@endsection
