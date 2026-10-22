@php /** @var string $eventTitle */ @endphp
@php /** @var string $code */ @endphp
@php /** @var int $validityMinutes */ @endphp

<x-mail::message>
# {{ __('Manage your attendance') }}

{{ __('Hello') }},

{{ __('Here is your code to confirm or cancel your attendance at :event:', ['event' => $eventTitle]) }}

<x-mail::panel>
<div style="font-size: 32px; font-weight: 700; letter-spacing: 8px; text-align: center;">{{ $code }}</div>
</x-mail::panel>

{{ __('Enter it on the event page. It is valid for :minutes minutes and can only be used once.', ['minutes' => $validityMinutes]) }}

{{ __('If you did not request this, please ignore this email.') }}

{{ __('Thank you') }}
</x-mail::message>
