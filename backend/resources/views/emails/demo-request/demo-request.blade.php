@php /** @var string $fullName */ @endphp
@php /** @var string $email */ @endphp
@php /** @var string $organization */ @endphp
@php /** @var string $eventType */ @endphp
@php /** @var string|null $eventDate */ @endphp
@php /** @var string $attendeeCount */ @endphp

@php /** @see \HiEvents\Mail\DemoRequest\DemoRequestEmail */ @endphp

<x-mail::message>
# {{ __('New demo request') }}

- **{{ __('Name') }}:** {{ $fullName }}
- **{{ __('Email') }}:** {{ $email }}
- **{{ __('Organization') }}:** {{ $organization }}
- **{{ __('Event type') }}:** {{ $eventType }}
- **{{ __('Estimated date') }}:** {{ $eventDate ?: __('Not specified') }}
- **{{ __('Number of attendees') }}:** {{ $attendeeCount }}

<x-mail::button :url="'mailto:' . $email">
{{ __('Reply to :name', ['name' => $fullName]) }}
</x-mail::button>

{{ __('This request was sent from the demo form on the landing page. Commitment: reply within 1 business day.') }}
</x-mail::message>
