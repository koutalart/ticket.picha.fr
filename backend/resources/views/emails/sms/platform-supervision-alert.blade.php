@php /** @var string $intro */ @endphp
@php /** @var array<string, string> $details */ @endphp
@php /** @var string|null $url */ @endphp

@php /** @see \HiEvents\Mail\Sms\PlatformSupervisionAlertMail */ @endphp

<x-mail::message>
{{ $intro }}

<div style="border-radius: 5px; background-color: #eeeeee; margin: 10px 0; padding: 12px 16px;">
@foreach($details as $label => $value)
**{{ $label }}** : {!! nl2br(e($value)) !!}<br>
@endforeach
</div>

@if($url !== null)
<x-mail::button :url="$url">
{{ __('Open in PICHA Ticket') }}
</x-mail::button>
@endif

</x-mail::message>
