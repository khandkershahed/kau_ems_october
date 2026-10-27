@extends('errors.layout')

@section('title', '403 - Access Forbidden')

@section('content')
    <div class="badge badge-403">403 &bull; Clearance Required</div>
    <div class="error-code">403</div>
    <div class="error-title">Access Forbidden</div>
    <div class="error-bangla">অননুমোদিত প্রবেশাধিকার</div>
    <p class="error-desc">
        You do not have the required administrative role or clearance to access this resource. Please contact the system administrator if you believe this is in error.
    </p>

    <div class="btn-group">
        <a href="/login" class="btn btn-primary">
            Sign In with Another Account
        </a>
        <button onclick="window.history.back()" class="btn btn-secondary">
            Previous Page
        </button>
    </div>
@endsection
