<x-mail::message>
# Welcome to Your New Subscription!

Hi {{ $user->name ?? 'there' }},

Thank you for subscribing! Your subscription is now active.

## Subscription Details

@if($plan)
**Plan:** {{ $plan->name }}
@endif
**Amount:** {{ $amount }} / {{ $subscription->interval }}
@if($trialEndsAt)
**Trial Ends:** {{ $trialEndsAt }}
@endif
@if($nextBillingDate)
**Next Billing Date:** {{ $nextBillingDate }}
@endif

@if($subscription->trial_end && $subscription->trial_end->isFuture())
<x-mail::panel>
You're currently on a free trial. Your first charge of {{ $amount }} will occur on {{ $trialEndsAt }}.
</x-mail::panel>
@endif

<x-mail::button :url="config('app.url')">
Manage Your Subscription
</x-mail::button>

If you have any questions, please don't hesitate to contact us.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
