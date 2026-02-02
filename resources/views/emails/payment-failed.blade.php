<x-mail::message>
# Payment Failed

Hi {{ $user->name ?? 'there' }},

Unfortunately, we were unable to process your recent payment.

## Payment Details

**Amount:** {{ $amount }}
**Date:** {{ $transaction->created_at->format('F j, Y \a\t g:i A') }}
**Reason:** {{ $reason }}

<x-mail::panel>
To avoid any interruption in your service, please update your payment method as soon as possible.
</x-mail::panel>

<x-mail::button :url="config('app.url')">
Update Payment Method
</x-mail::button>

If you believe this is an error or need assistance, please contact our support team.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
