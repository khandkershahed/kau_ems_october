@extends('errors.layout')

@section('title', '401 - Authentication Required')

@section('content')
    <div class="badge badge-401">401 &bull; Unauthenticated Session</div>
    <div class="error-code">401</div>
    <div class="error-title">Authentication Required</div>
    <div class="error-bangla">অনুমোদনের জন্য লগইন প্রয়োজন</div>
    <p class="error-desc">
        Your active session could not be verified or has timed out. Please sign in with your institutional credentials to proceed.
    </p>

    <div class="btn-group">
        <a href="/login" class="btn btn-primary">
            Sign In to KAU Portal
        </a>
        <a href="/" class="btn btn-secondary">
            Return to Homepage
        </a>
    </div>
@endsection
