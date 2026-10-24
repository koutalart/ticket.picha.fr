@php /** @var string $organizerName */ @endphp
@php /** @var string $text */ @endphp

@php /** @see \HiEvents\Mail\LiveChat\LiveChatRefundUpdateMail */ @endphp

<x-mail::message :emailLogo="$emailLogo ?? null">
{!! nl2br(e($text)) !!}

{{ $organizerName }}
</x-mail::message>
