@php use HiEvents\Helper\EmailFormatHelper; @endphp
@php /** @var \HiEvents\DomainObjects\AttendeeDomainObject $attendee */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\AttendeeDomainObject[] $tickets */ @endphp
@php /** @var string $ticketUrl */ @endphp
@php /** @see \HiEvents\Mail\Attendee\AttendeeTicketMail */ @endphp
@php
    $ticketName = $order->getOrderItems()
        ?->first(fn ($item) => $item->getProductPriceId() === $attendee->getProductPriceId())
        ?->getItemName()
        ?? $attendee->getProduct()?->getTitle();
    $attendeeName = trim($attendee->getFirstName().' '.$attendee->getLastName());
    $supportEmail = $eventSettings->getSupportEmail() ?: $organizer->getEmail();
    $tickets = $tickets ?? [$attendee];
    $ticketCount = count($tickets);
    $ticketLabel = fn ($ticket) => trim(($order->getOrderItems()
        ?->first(fn ($item) => $item->getProductPriceId() === $ticket->getProductPriceId())
        ?->getItemName() ?? $ticket->getProduct()?->getTitle() ?? '')
        .' — '.trim($ticket->getFirstName().' '.$ticket->getLastName()), ' —');
@endphp

<x-mail::message
    :isFreeEvent="$isFreeEvent ?? false"
    :organizerLogoUrl="$organizerLogoUrl ?? null"
    :organizer="$organizer ?? null"
>
# {{ $event->getTitle() }} 🎉

@if($order->isOrderAwaitingOfflinePayment())
<div style="border-radius: 4px; background-color: #f8d7da; color: #842029; margin-bottom: 1.5rem; padding: 1rem;">
<p>
{{ __('ℹ️ Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>
</div>
@endif

@if($attendeeName !== '')
{{ __('Hello :name,', ['name' => $attendeeName]) }}
@endif

{{ trans_choice('Your ticket is attached to this email (PDF). Show its QR code at the entrance, on your phone or printed.|Your tickets are attached to this email (PDF). Show their QR codes at the entrance, on your phone or printed.', $ticketCount) }}

@if($event->getStartDate())
**{{ __('Date & Time:') }}** {{ EmailFormatHelper::eventDateTime($event) }}<br>
@endif
@if(trim($eventSettings->getAddressString()) !== '')
**{{ __('Location:') }}** {{ $eventSettings->getAddressString() }}<br>
@endif
@if($ticketCount === 1)
@if($ticketName)
**{{ __('Ticket Type:') }}** {{ $ticketName }}<br>
@endif
@if($attendeeName !== '')
**{{ __('Attendee:') }}** {{ $attendeeName }}
@endif
@else

**{{ trans_choice('Your ticket|Your tickets', $ticketCount) }} ({{ $ticketCount }})**<br>
@foreach($tickets as $ticket)
🎟️ {{ $ticketLabel($ticket) }}<br>
@endforeach
@endif

<x-mail::button :url="$ticketUrl">
{{ $ticketCount === 1 ? __('View Ticket') : __('View Order & Tickets') }}
</x-mail::button>

@if($supportEmail)
{!! __('If you have any questions, reply to this email or contact the organizer at :email.', ['email' => '<a href="mailto:'.e($supportEmail).'">'.e($supportEmail).'</a>']) !!}
@endif

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

</x-mail::message>
