<x-mail::message>
@if ($type === 'radar.early_fraud_warning.created')
# Early Fraud Warning

**Warning:** {{ $dispute['id'] ?? 'unknown' }}<br>
**Charge:** {{ $dispute['charge'] ?? 'unknown' }}<br>
**Fraud type:** {{ $dispute['fraud_type'] ?? 'unknown' }}<br>
**Actionable:** {{ ($dispute['actionable'] ?? false) ? 'yes' : 'no' }}

The card issuer reported this charge as fraud. If it is actionable, refund it and cancel the subscription in the Stripe Dashboard to avoid a dispute fee.
@else
# Payment Dispute

**Event:** {{ $type }}<br>
**Dispute:** {{ $dispute['id'] ?? 'unknown' }}<br>
**Charge:** {{ $dispute['charge'] ?? 'unknown' }}<br>
**Amount:** {{ $amount }}<br>
**Reason:** {{ $dispute['reason'] ?? 'unknown' }}<br>
**Status:** {{ $dispute['status'] ?? 'unknown' }}

Review it in the Stripe Dashboard before the response deadline.
@endif
</x-mail::message>
