@extends('layouts.app')

@section('title', 'Donate')

@section('content')
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h1 class="h3 mb-1">Support our work</h1>
        <p class="text-muted mb-4">Every gift helps. Payments are processed securely by Stripe.</p>

        @if ($testMode)
            <div class="alert alert-warning small">
                <strong>Demo in Stripe test mode:</strong> no real money moves. Pay with card
                <code>4242 4242 4242 4242</code>, any future date and any CVC. Please don't enter a real card.
            </div>
        @endif

        <form method="POST" action="{{ route('donations.store') }}" novalidate>
            @csrf

            {{-- Frequency: two radio buttons styled as a toggle --}}
            <div class="btn-group w-100 mb-4" role="group" aria-label="Donation frequency">
                <input type="radio" class="btn-check" name="frequency" id="freq-once" value="one_time"
                       @checked(old('frequency', 'one_time') === 'one_time')>
                <label class="btn btn-outline-primary" for="freq-once">Give once</label>

                <input type="radio" class="btn-check" name="frequency" id="freq-monthly" value="monthly"
                       @checked(old('frequency') === 'monthly')>
                <label class="btn btn-outline-primary" for="freq-monthly">Monthly</label>
            </div>
            @error('frequency') <div class="text-danger small mb-3">{{ $message }}</div> @enderror

            {{-- Preset amounts fill the amount field below --}}
            <div class="row g-2 mb-2">
                @foreach ($presets as $preset)
                    <div class="col-6 col-sm-3">
                        <button type="button" class="btn btn-outline-secondary w-100 preset" data-amount="{{ $preset }}">
                            ${{ $preset }}
                        </button>
                    </div>
                @endforeach
            </div>

            <div class="mb-3">
                <label for="amount" class="form-label">Amount (AUD)</label>
                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="number" step="0.01" min="2" name="amount" id="amount"
                           class="form-control @error('amount') is-invalid @enderror"
                           value="{{ old('amount', 25) }}" required>
                    @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="name" class="form-label">Full name</label>
                <input type="text" name="name" id="name" autocomplete="name"
                       class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-4">
                <label for="email" class="form-label">Email for your receipt</label>
                <input type="email" name="email" id="email" autocomplete="email"
                       class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" required>
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100">Continue to secure payment</button>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.preset').forEach(button => {
        button.addEventListener('click', () => {
            document.getElementById('amount').value = button.dataset.amount;
        });
    });
</script>
@endsection
