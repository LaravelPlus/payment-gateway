<x-mail::message>
# Payment Receipt

Thank you for your payment, {{ $user->name ?? 'Customer' }}!

**Transaction ID:** {{ $transaction->provider_id }}
**Amount:** {{ $amount }}
**Date:** {{ $transaction->created_at->format('F j, Y \a\t g:i A') }}
**Status:** {{ ucfirst($transaction->status) }}

@if($transaction->description)
**Description:** {{ $transaction->description }}
@endif

@if($invoice)
## Invoice Details

**Invoice Number:** {{ $invoice->number }}

| Description | Qty | Amount |
|:------------|:---:|-------:|
@foreach($invoice->line_items ?? [] as $item)
| {{ $item['description'] }} | {{ $item['quantity'] }} | {{ $invoice->currency }} {{ number_format($item['amount'] / 100, 2) }} |
@endforeach

**Total:** {{ $invoice->formatted_total }}
@endif

<x-mail::button :url="config('app.url')">
View Your Account
</x-mail::button>

If you have any questions about this payment, please contact our support team.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
