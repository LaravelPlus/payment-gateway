<x-mail::message>
# Your Trial Ends Soon

Hi {{ $user->name ?? 'there' }},

Just a friendly reminder that your free trial will end in **{{ $daysRemaining }} days** on **{{ $trialEndsAt }}**.

## What Happens Next

After your trial ends, your subscription will automatically convert to a paid plan:

@if($plan)
**Plan:** {{ $plan->name }}
@endif
**Amount:** {{ $amount }} / {{ $subscription->interval }}
**First Charge:** {{ $trialEndsAt }}

<x-mail::panel>
Make sure your payment method is up to date to avoid any interruption in service.
</x-mail::panel>

<x-mail::button :url="config('app.url')">
Manage Your Subscription
</x-mail::button>

If you don't wish to continue, you can cancel your subscription before the trial ends to avoid being charged.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
