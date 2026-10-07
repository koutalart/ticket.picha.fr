@php use HiEvents\Helper\EmailFormatHelper; @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var string $orderUrl */ @endphp
@php /** @var \HiEvents\DomainObjects\AttendeeDomainObject[] $ticketAttendees */ @endphp

@php /** @see \HiEvents\Mail\Order\OrderSummary */ @endphp

<x-mail::message>
# {{ __('Your Order is Confirmed! ') }} 🎉

@if($order->isOrderAwaitingOfflinePayment() === false)

<p>
{{ __('Thank you! Your order for :eventTitle is confirmed. Here are the details.', ['eventTitle' => $event->getTitle()]) }}
</p>

@else

<div>
<p>
{{ __('Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>

<div style="border-radius: 4px; background-color: #d7e8f8; color: #204e84; margin-bottom: 1.5rem; padding: 1rem;">
<h2>{{ __('Payment Instructions') }}</h2>
{{ __('Please follow the instructions below to complete your payment.') }}
{!! $eventSettings->getOfflinePaymentInstructions() !!}
</div>
</div>

@endif

# {{ __('Event Details') }}
**{{ __('Event Name:') }}** {{ $event->getTitle() }}<br>
@if($event->getStartDate())
**{{ __('Date & Time:') }}** {{ EmailFormatHelper::eventDateTime($event) }}<br>
@endif
@if(trim($eventSettings->getAddressString()) !== '')
**{{ __('Location:') }}** {{ $eventSettings->getAddressString() }}<br>
@endif

@if($eventSettings->getPostCheckoutMessage() && $order->isOrderCompleted())
<p>

# {{ __('Additional Information') }}

{!! $eventSettings->getPostCheckoutMessage() !!}

</p>
@endif

@if(!empty($ticketAttendees))
# {{ trans_choice('Your ticket|Your tickets', count($ticketAttendees)) }}
{{ trans_choice('Your ticket is attached to this email (PDF). Show its QR code at the entrance, on your phone or printed.|Your tickets are attached to this email (PDF). Show their QR codes at the entrance, on your phone or printed.', count($ticketAttendees)) }}

@foreach($ticketAttendees as $ticketAttendee)
- **{{ $order->getOrderItems()?->first(fn ($item) => $item->getProductPriceId() === $ticketAttendee->getProductPriceId())?->getItemName() ?? __('Ticket') }}** — {{ trim($ticketAttendee->getFirstName().' '.$ticketAttendee->getLastName()) }}
@endforeach

@endif
# {{ __('Order Summary') }}
**{{ __('Order Number:') }}** {{ $order->getPublicId() }}<br>
**{{ __('Total Amount:') }}** {{ EmailFormatHelper::money($order->getTotalGross(), $event->getCurrency()) }}

<x-mail::button :url="$orderUrl">
    {{ __('View Order Summary & Tickets') }}
</x-mail::button>

{!! __('If you have any questions, reply to this email or contact the organizer at :email.', ['email' => '<a href="mailto:'.e($organizer->getEmail()).'">'.e($organizer->getEmail()).'</a>']) !!}

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}
</x-mail::message>
