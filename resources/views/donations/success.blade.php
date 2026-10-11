@extends('layouts.app')

@section('title', 'Thank you')

@section('content')
<div class="card shadow-sm text-center">
    <div class="card-body p-5">
        <h1 class="h3">Thank you{{ $donation ? ', '.$donation->name : '' }}!</h1>
        <p class="text-muted mb-4">
            Your payment is being confirmed. A receipt will be emailed to
            {{ $donation?->email ?? 'you' }} shortly.
        </p>
        <a href="{{ route('donations.create') }}" class="btn btn-outline-primary">Make another donation</a>
    </div>
</div>
@endsection
