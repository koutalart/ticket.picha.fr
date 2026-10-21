@php /** @var string $organizerName */ @endphp
@php /** @var string|null $phoneNumber */ @endphp
@php /** @var string|null $offlineSince */ @endphp
@php /** @var string $settingsUrl */ @endphp

@php /** @see \HiEvents\Mail\Sms\SmsPhoneOfflineAlertMail */ @endphp

<x-mail::message>
{{ __('Hello :name', ['name' => $organizerName]) }},

@if($phoneNumber)
{{ __('The Android phone used to send and receive your SMS (:number) is offline.', ['number' => $phoneNumber]) }}
@else
{{ __('The Android phone used to send and receive your SMS is offline.') }}
@endif

@if($offlineSince)
{{ __('Last seen: :date', ['date' => $offlineSince]) }}
@endif

{{ __('As long as it stays offline, SMS replies from your contacts will not reach PICHA Ticket and your messages will not be sent.') }}

{{ __('Check that the phone is switched on, connected to the internet and that the httpSMS app is running.') }}

<x-mail::button :url="$settingsUrl">
{{ __('View SMS settings') }}
</x-mail::button>

</x-mail::message>
