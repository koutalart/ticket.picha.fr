@php /** @var string $organizerName */ @endphp
@php /** @var string $contact */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\DomainObjects\SmsMessageDomainObject> $messages */ @endphp
@php /** @var int $unreadCount */ @endphp
@php /** @var string $inboxUrl */ @endphp

@php /** @see \HiEvents\Mail\Sms\SmsInboundAlertMail */ @endphp

<x-mail::message>
{{ __('Hello :name', ['name' => $organizerName]) }},

{{ __('You have received a new SMS from') }} **{{ $contact }}**.

@foreach($messages as $message)
<div style="border-radius: 5px; background-color: #eeeeee; margin: 10px 0; padding: 15px 20px;">

{!! nl2br(e($message->getContent())) !!}

</div>
@endforeach

@if($unreadCount > $messages->count())
{{ __(':count unread messages in this conversation.', ['count' => $unreadCount]) }}
@endif

<x-mail::button :url="$inboxUrl">
{{ __('Reply in PICHA Ticket') }}
</x-mail::button>

{{ __('To avoid too many emails, you will receive at most one alert per conversation every 15 minutes. You can turn these alerts off in Settings > SMS (httpSMS).') }}

</x-mail::message>
