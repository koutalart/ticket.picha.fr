@php /** @var string $eventTitle */ @endphp
@php /** @var string $code */ @endphp
@php /** @var int $validityMinutes */ @endphp
@php /** @var string|null $firstName */ @endphp
@php /** @var string $organizerName */ @endphp

@php /** @see \HiEvents\Mail\Attendee\AttendanceAccessCodeMail */ @endphp

<x-mail::message :emailLogo="$emailLogo ?? null">
# {{ __('Manage your attendance') }}

@if($firstName)
{{ __('Hello :name,', ['name' => $firstName]) }}
@else
{{ __('Hello') }},
@endif

{{ __('Here is your code for :event:', ['event' => $eventTitle]) }}

<div style="background-color: #edf2f7; border-radius: 8px; padding: 18px 0; margin: 16px 0; font-size: 32px; font-weight: 700; letter-spacing: 8px; text-align: center; color: #2d3748;">{{ $code }}</div>

{{ __('Enter it on the event page. It is valid for :minutes minutes and can only be used once.', ['minutes' => $validityMinutes]) }}

{{ __('If you did not request this, please ignore this email.') }}

{{ __('Best regards,') }}<br>
{{ $organizerName }}
</x-mail::message>
