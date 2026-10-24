@php /** @var string $organizerName */ @endphp
@php /** @var string $eventTitle */ @endphp
@php /** @var string $code */ @endphp
@php /** @var int $validityMinutes */ @endphp
@php /** @var string $eventUrl */ @endphp

@php /** @see \HiEvents\Mail\LiveChat\LiveChatRefundCodeMail */ @endphp

<x-mail::message :emailLogo="$emailLogo ?? null">
# {{ __('Cancel your order') }}

{{ __('Hello') }},

{{ __('You asked :organizer, in the chat of its pages, to cancel your order for :event. Here is your code:', ['organizer' => $organizerName, 'event' => $eventTitle]) }}

<div style="background-color: #edf2f7; border-radius: 8px; padding: 18px 0; margin: 16px 0; font-size: 32px; font-weight: 700; letter-spacing: 8px; text-align: center; color: #2d3748;">{{ $code }}</div>

{{ __('Enter it in the chat. It is valid for :minutes minutes. Nothing is cancelled until you confirm.', ['minutes' => $validityMinutes]) }}

{{ __('If you did not make this request, ignore this e-mail: your order stays as it is.') }}

<x-mail::button :url="$eventUrl">
{{ __('See the event') }}
</x-mail::button>

</x-mail::message>
