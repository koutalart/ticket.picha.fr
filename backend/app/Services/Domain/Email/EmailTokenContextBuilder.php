<?php

namespace HiEvents\Services\Domain\Email;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\AddressHelper;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\EmailFormatHelper;
use HiEvents\Helper\IdHelper;
use HiEvents\Helper\Url;
use Illuminate\Support\Traits\Localizable;

class EmailTokenContextBuilder
{
    use Localizable;

    public function buildOrderConfirmationContext(
        OrderDomainObject        $order,
        EventDomainObject        $event,
        OrganizerDomainObject    $organizer,
        EventSettingDomainObject $eventSettings
    ): array
    {
        return $this->withLocale(CustomerLocale::get(), fn () => $this->orderConfirmationContext($order, $event, $organizer, $eventSettings));
    }

    private function orderConfirmationContext(
        OrderDomainObject        $order,
        EventDomainObject        $event,
        OrganizerDomainObject    $organizer,
        EventSettingDomainObject $eventSettings
    ): array
    {
        $timezone = $event->getTimezone();

        return [
            // Event object
            'event' => [
                'title' => $event->getTitle(),
                'date' => EmailFormatHelper::eventDate($event),
                'time' => EmailFormatHelper::eventTime($event),
                'end_date' => $event->getEndDate() ? EmailFormatHelper::localDate($event->getEndDate(), $timezone) : '',
                'end_time' => $event->getEndDate() ? EmailFormatHelper::localTime($event->getEndDate(), $timezone) : '',
                'full_address' => $eventSettings->getLocationDetails() ? AddressHelper::formatAddress($eventSettings->getLocationDetails()) : '',
                'location_details' => $eventSettings->getLocationDetails(),
                'description' => $event->getDescription() ?? '',
                'timezone' => $event->getTimezone(),
            ],

            // Order object
            'order' => [
                'url' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY),
                    $event->getId(),
                    $order->getShortId()
                ),
                'number' => $order->getPublicId(),
                'total' => EmailFormatHelper::money($order->getTotalGross(), $event->getCurrency()),
                'date' => EmailFormatHelper::localDate($order->getCreatedAt(), $timezone),
                'currency' => $order->getCurrency(), // added
                'locale' => $order->getLocale(), // added
                'first_name' => $order->getFirstName() ?? '',
                'last_name' => $order->getLastName() ?? '',
                'email' => $order->getEmail() ?? '',
                'is_awaiting_offline_payment' => $order->isOrderAwaitingOfflinePayment(),
                'is_offline_payment' => $order->getPaymentProvider() === PaymentProviders::OFFLINE->value,
            ],

            // Organizer object
            'organizer' => [
                'name' => $organizer->getName() ?? '',
                'email' => $organizer->getEmail() ?? '',
            ],

            // Settings object
            'settings' => [
                'support_email' => $eventSettings->getSupportEmail() ?? $organizer->getEmail() ?? '',
                'offline_payment_instructions' => $eventSettings->getOfflinePaymentInstructions() ?? '',
                'post_checkout_message' => $eventSettings->getPostCheckoutMessage() ?? '',
            ],
        ];
    }

    public function buildAttendeeTicketContext(
        AttendeeDomainObject     $attendee,
        OrderDomainObject        $order,
        EventDomainObject        $event,
        OrganizerDomainObject    $organizer,
        EventSettingDomainObject $eventSettings
    ): array
    {
        $baseContext = $this->buildOrderConfirmationContext($order, $event, $organizer, $eventSettings);

        /** @var OrderItemDomainObject $orderItem */
        $orderItem = $order->getOrderItems()->first(fn(OrderItemDomainObject $item) => $item->getProductPriceId() === $attendee->getProductPriceId());

        $ticketPrice = $this->withLocale(CustomerLocale::get(), fn () => EmailFormatHelper::money($orderItem?->getPrice() ?? 0, $event->getCurrency()));
        $ticketName = $orderItem?->getItemName();

        // Add attendee and ticket objects
        $baseContext['attendee'] = [
            'name' => $attendee->getFirstName() . ' ' . $attendee->getLastName(),
            'email' => $attendee->getEmail() ?? '',
        ];

        $baseContext['ticket'] = [
            'name' => $ticketName,
            'price' => $ticketPrice,
            'url' => sprintf(
                Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                $event->getId(),
                $attendee->getShortId()
            ),
        ];

        return $baseContext;
    }

    public function buildPreviewContext(string $templateType): array
    {
        return $this->withLocale(CustomerLocale::get(), fn () => $this->previewContext($templateType));
    }

    private function previewContext(string $templateType): array
    {
        $timezone = 'Indian/Mayotte';
        $start = '2029-04-25 15:00:00';
        $end = '2029-04-25 20:00:00';

        $baseContext = [
            'event' => [
                'title' => __('Summer Music Festival 2024'),
                'date' => EmailFormatHelper::localDate($start, $timezone),
                'time' => EmailFormatHelper::localTime($start, $timezone),
                'end_date' => EmailFormatHelper::localDate($end, $timezone),
                'end_time' => EmailFormatHelper::localTime($end, $timezone),
                'description' => __('Join us for an unforgettable evening of live music featuring top artists from around the world.'),
                'timezone' => $timezone,
                'location_details' => [
                    'venue_name' => 'Le 5/5',
                    'address_line_1' => 'Rond point de la barge',
                    'address_line_2' => '',
                    'city' => 'Mamoudzou',
                    'state_or_region' => '',
                    'zip_or_postal_code' => '97600',
                    'country' => 'YT',
                ]
            ],
            'order' => [
                'url' => 'https://example.com/order/ABC123',
                'number' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
                'total' => EmailFormatHelper::money(150, 'EUR'),
                'date' => EmailFormatHelper::localDate('2029-01-10 08:00:00', $timezone),
                'first_name' => 'Fatima',
                'last_name' => 'Ali',
                'email' => 'fatima@example.com',
                'is_awaiting_offline_payment' => false,
                'is_offline_payment' => false,
                'locale' => CustomerLocale::get(),
                'currency' => 'EUR'
            ],
            'organizer' => [
                'name' => __('ACME Events Inc.'),
                'email' => 'contact@example.com',
            ],
            'settings' => [
                'support_email' => 'support@example.com',
                'offline_payment_instructions' => __('Please transfer the total amount to the following bank account within 5 business days.'),
                'post_checkout_message' => __('Thank you for your purchase! We look forward to seeing you at the event.'),
            ],
        ];

        $baseContext['event']['full_address'] = AddressHelper::formatAddress($baseContext['event']['location_details']);

        if ($templateType === 'attendee_ticket') {
            $baseContext['attendee'] = [
                'name' => 'Fatima Ali',
                'email' => 'fatima@example.com',
            ];
            $baseContext['ticket'] = [
                'name' => __('VIP Pass'),
                'price' => EmailFormatHelper::money(75, 'EUR'),
                'url' => 'https://example.com/ticket/XYZ789',
            ];
        }

        return $baseContext;
    }
}
