@php /** @var array<int, array{organizer: string, lines: array<int, array{type: string, conversation: string, phone: string, time: string, detail: string, excerpt: ?string}>, hiddenCount: int}> $groups */ @endphp

@php /** @see \HiEvents\Mail\Sms\SmsWatchdogAlertMail */ @endphp

<x-mail::message>
{{ __('The SMS watchdog found new anomalies (times in Mayotte time).') }}

@foreach($groups as $group)
## {{ $group['organizer'] }}

@foreach($group['lines'] as $line)
<div style="border-radius: 5px; background-color: #eeeeee; margin: 10px 0; padding: 12px 16px;">

**{{ $line['type'] }}**, {{ $line['detail'] }}<br>
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

@if($group['hiddenCount'] > 0)
{{ __('And :count more.', ['count' => $group['hiddenCount']]) }}
@endif

@endforeach

{{ __('Each anomaly is reported once. It is reported again only if it disappears and comes back.') }}

</x-mail::message>
