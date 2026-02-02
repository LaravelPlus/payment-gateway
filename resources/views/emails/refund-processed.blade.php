<x-mail::message>
# Refund Processed

Hi {{ $user->name ?? 'there' }},

Good news! Your refund has been processed successfully.

## Refund Details

**Refund Amount:** {{ $amount }}
**Original Transaction:** {{ $transaction->provider_id ?? 'N/A' }}
**Refund ID:** {{ $refund->provider_id }}
**Status:** {{ ucfirst($refund->status) }}

@if($refund->reason)
**Reason:** {{ $refund->reason }}
@endif

<x-mail::panel>
Please allow 5-10 business days for the refund to appear in your account, depending on your payment method and bank.
</x-mail::panel>

<x-mail::button :url="config('app.url')">
View Your Account
</x-mail::button>

If you have any questions about this refund, please contact our support team.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
