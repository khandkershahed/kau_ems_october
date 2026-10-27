@extends('errors.layout')

@section('title', '404 - Page Not Found')

@section('content')
    <div class="badge badge-404">404 &bull; Resource Not Found</div>
    <div class="error-code">404</div>
    <div class="error-title">Page Not Found</div>
    <div class="error-bangla">পাতাটি পাওয়া যায়নি</div>
    <p class="error-desc">
        The requested page or administrative resource could not be found. It may have been moved, renamed, or is temporarily unavailable.
    </p>

    <div class="btn-group">
        <a href="/login" class="btn btn-primary">
            Sign In to KAU Portal
        </a>
        <button onclick="window.history.back()" class="btn btn-secondary">
            Previous Page
        </button>
    </div>
@endsection
