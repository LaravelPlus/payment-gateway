<x-mail::message>
# Payment Failed

Hi {{ $user->name ?? 'there' }},

We could not charge your card for your subscription.

**Amount due:** {{ $amount }}

We will try again automatically. To keep your service running, please check your payment method or pay the invoice now.

@if ($invoiceUrl)
<x-mail::button :url="$invoiceUrl">
Pay Invoice
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
