@php /** @var string $email */ @endphp
@php /** @var int $orderCount */ @endphp
@php /** @var string $ticketLookupUrl */ @endphp
@php /** @var string|null $firstName */ @endphp
@php /** @var string $organizerName */ @endphp

@php /** @see \HiEvents\Mail\TicketLookup\TicketLookupEmail */ @endphp

<x-mail::message>
# {{ __('Your Tickets') }}

@if($firstName)
{{ __('Hello :name,', ['name' => $firstName]) }}
@else
{{ __('Hello') }},
@endif

{{ __('We found :count order(s) associated with :email.', ['count' => $orderCount, 'email' => $email]) }}

{{ __('Click the button below to view your tickets and order details.') }}

<x-mail::button :url="$ticketLookupUrl">
{{ __('View My Tickets') }}
</x-mail::button>

{{ __('This link will expire in 24 hours.') }}

{{ __('If you did not request this, please ignore this email.') }}

{{ __('Best regards,') }}<br>
{{ $organizerName }}
</x-mail::message>
