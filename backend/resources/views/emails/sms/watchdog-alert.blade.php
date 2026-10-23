@php /** @var array<int, array{type: string, organizer: string, conversation: string, phone: string, time: string, detail: string, excerpt: ?string}> $lines */ @endphp
@php /** @var int $hiddenCount */ @endphp

@php /** @see \HiEvents\Mail\Sms\SmsWatchdogAlertMail */ @endphp

<x-mail::message>
{{ __('The SMS watchdog found new anomalies (times in Mayotte time).') }}

@foreach($lines as $line)
<div style="border-radius: 5px; background-color: #eeeeee; margin: 10px 0; padding: 12px 16px;">

**{{ $line['type'] }}**, {{ $line['detail'] }}<br>
{{ __('Organizer') }} : {{ $line['organizer'] }}<br>
@if($line['conversation'] !== '')
{{ __('Conversation') }} : {{ $line['conversation'] }}<br>
@endif
{{ __('Number') }} : {{ $line['phone'] }}<br>
{{ __('Time') }} : {{ $line['time'] }}
@if($line['excerpt'] !== null)
<br>« {{ $line['excerpt'] }} »
@endif

</div>
@endforeach

@if($hiddenCount > 0)
{{ __('And :count more.', ['count' => $hiddenCount]) }}
@endif

{{ __('Each anomaly is reported once. It is reported again only if it disappears and comes back.') }}

</x-mail::message>
