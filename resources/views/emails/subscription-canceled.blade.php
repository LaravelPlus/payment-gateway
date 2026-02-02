<x-mail::message>
# Your Subscription Has Been Canceled

Hi {{ $user->name ?? 'there' }},

We're sorry to see you go. Your subscription has been canceled.

## What Happens Next

@if($endsAt)
You will continue to have access to your subscription benefits until **{{ $endsAt }}**. After this date, your subscription will be fully deactivated.
@else
Your subscription has been immediately deactivated.
@endif

@if($plan)
**Plan:** {{ $plan->name }}
@endif

<x-mail::panel>
Changed your mind? You can resubscribe at any time by visiting your account settings.
</x-mail::panel>

<x-mail::button :url="config('app.url')">
Resubscribe
</x-mail::button>

If you have any feedback about why you canceled, we'd love to hear from you.

Thanks for being a customer,<br>
{{ config('app.name') }}
</x-mail::message>
