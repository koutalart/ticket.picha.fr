@php /** @var string $organizerName */ @endphp
@php /** @var string $eventTitle */ @endphp
@php /** @var string $orderReference */ @endphp
@php /** @var string $buyerName */ @endphp
@php /** @var string $buyerEmail */ @endphp
@php /** @var string $amount */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundTicketDTO> $tickets */ @endphp
@php /** @var \Illuminate\Support\Collection<int, \HiEvents\DomainObjects\LiveChatMessageDomainObject> $transcript */ @endphp
@php /** @var string $orderUrl */ @endphp

@php /** @see \HiEvents\Mail\LiveChat\LiveChatRefundAlertMail */ @endphp

<x-mail::message>
{{ __('Hello :name', ['name' => $organizerName]) }},

{{ __('The web chat refunded an order covered by the cancellation protection, after checking the order number, the e-mail of the order and a code sent to that e-mail.') }}

**{{ __('Amount refunded') }}** : {{ $amount }}<br>
**{{ __('Order') }}** : {{ $orderReference }}, {{ $eventTitle }}<br>
**{{ __('Buyer') }}** : {{ $buyerName }} ({{ $buyerEmail }})

**{{ __('Cancelled tickets') }}**

@foreach($tickets as $ticket)
{{ $ticket->name }}, {{ $ticket->category }}<br>
@endforeach

{{ __('The service fees and the protection are not refunded. The buyer received the usual refund e-mail.') }}

@foreach($transcript as $message)
<div style="border-radius: 5px; background-color: {{ $message->getAuthorType() === 'VISITOR' ? '#eeeeee' : '#f7f4ff' }}; margin: 10px 0; padding: 12px 18px;">
<strong>{{ $message->getAuthorType() === 'VISITOR' ? __('Visitor') : __('Assistant') }}</strong><br>
{!! nl2br(e($message->getContent())) !!}
</div>
@endforeach

<x-mail::button :url="$orderUrl">
{{ __('See the order') }}
</x-mail::button>

</x-mail::message>
