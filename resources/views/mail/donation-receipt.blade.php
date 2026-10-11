<x-mail::message>
# Thank you, {{ $donation->name }}

We've received your {{ $donation->isMonthly() ? 'monthly' : 'one-off' }} donation.

<x-mail::table>
| | |
|:--|--:|
| Receipt number | #{{ $payment->id }} |
| Date | {{ $payment->paid_at->format('j M Y') }} |
| Amount | ${{ $amount }} {{ strtoupper($donation->currency) }} |
| Frequency | {{ $donation->isMonthly() ? 'Monthly' : 'One-off' }} |
</x-mail::table>

@if ($donation->isMonthly())
You'll receive a receipt like this each month your donation is processed.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
