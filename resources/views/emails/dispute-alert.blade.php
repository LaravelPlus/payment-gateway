<x-mail::message>
# Payment Dispute

**Event:** {{ $type }}<br>
**Dispute:** {{ $dispute['id'] ?? 'unknown' }}<br>
**Charge:** {{ $dispute['charge'] ?? 'unknown' }}<br>
**Amount:** {{ $amount }}<br>
**Reason:** {{ $dispute['reason'] ?? 'unknown' }}<br>
**Status:** {{ $dispute['status'] ?? 'unknown' }}

Review it in the Stripe Dashboard before the response deadline.
</x-mail::message>
