@php /** @var string $organizerName */ @endphp
@php /** @var string $email */ @endphp
@php /** @var string|null $reason */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\DomainObjects\LiveChatMessageDomainObject> $transcript */ @endphp
@php /** @var string $chatsUrl */ @endphp

@php /** @see \HiEvents\Mail\LiveChat\LiveChatContactAlertMail */ @endphp

<x-mail::message>
{{ __('Hello :name', ['name' => $organizerName]) }},

{{ __('A visitor of your pages asked a question in the web chat and left their e-mail address so that you can answer them:') }} **{{ $email }}**

@if($reason)
{{ __('To handle:') }} {{ $reason }}
@endif

@foreach($transcript as $message)
<div style="border-radius: 5px; background-color: {{ $message->getAuthorType() === 'VISITOR' ? '#eeeeee' : '#f7f4ff' }}; margin: 10px 0; padding: 12px 18px;">
<strong>{{ $message->getAuthorType() === 'VISITOR' ? __('Visitor') : __('Assistant') }}</strong><br>
{!! nl2br(e($message->getContent())) !!}
</div>
@endforeach

{{ __('Reply to this e-mail to answer the visitor directly.') }}

<x-mail::button :url="$chatsUrl">
{{ __('See the web chats') }}
</x-mail::button>

</x-mail::message>
