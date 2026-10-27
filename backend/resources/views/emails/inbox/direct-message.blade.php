@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var string $bodyHtml */ @endphp

@php /** @see \HiEvents\Mail\Inbox\OrganizerDirectEmail */ @endphp

<x-mail::message :emailLogo="$emailLogo ?? null">
<div>{!! $bodyHtml !!}</div>

<div style="color: #888; margin-top: 30px; font-size: .8em;">
{{ __('This message was sent by :organizer. Simply reply to this e-mail to answer.', ['organizer' => $organizer->getName()]) }}
</div>
</x-mail::message>
