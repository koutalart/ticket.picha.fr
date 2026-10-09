<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Stripe;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\PartialEditAttendeeDTO;
use HiEvents\Services\Application\Handlers\Attendee\PartialEditAttendeeHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class RefundOrderHandler
{
    public function __construct(
        private readonly StripePaymentIntentRefundService $refundService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly Mailer $mailer,
        private readonly OrderCancelService $orderCancelService,
        private readonly DatabaseManager $databaseManager,
        private readonly StripeClientFactory $stripeClientFactory,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly PartialEditAttendeeHandler $partialEditAttendeeHandler,
    ) {}

    /**
     * @throws RefundNotPossibleException
     * @throws ApiErrorException
     * @throws Throwable
     */
    public function handle(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        return $this->databaseManager->transaction(fn () => $this->refundOrder($refundOrderDTO));
    }

    /**
     * @param  int[]  $attendeeIds
     * @return AttendeeDomainObject[]
     *
     * @throws RefundNotPossibleException
     */
    private function refundedAttendees(OrderDomainObject $order, array $attendeeIds): array
    {
        if ($attendeeIds === []) {
            return [];
        }

        $attendees = $this->attendeeRepository->findWhereIn('id', $attendeeIds, [
            'order_id' => $order->getId(),
            'status' => AttendeeStatus::ACTIVE->name,
        ]);

        if ($attendees->count() !== count(array_unique($attendeeIds))) {
            throw new RefundNotPossibleException(__('Only active tickets of this order can be refunded.'));
        }

        return $attendees->all();
    }

    private function fetchOrder(int $eventId, int $orderId): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findFirstWhere(['event_id' => $eventId, 'id' => $orderId]);

        if (! $order) {
            throw new ResourceNotFoundException(__('Order :id not found for event :eventId', [
                'id' => $orderId,
                'eventId' => $eventId,
            ]));
        }

        return $order;
    }

    /**
     * @throws RefundNotPossibleException
     */
    private function validateRefundability(OrderDomainObject $order): void
    {
        if (! $order->getStripePayment()) {
            throw new RefundNotPossibleException(__('There is no Stripe data associated with this order.'));
        }

        if ($order->getRefundStatus() === OrderRefundStatus::REFUND_PENDING->name) {
            throw new RefundNotPossibleException(
                __('There is already a refund pending for this order.
                Please wait for the refund to be processed before requesting another one.')
            );
        }
    }

    private function notifyBuyer(OrderDomainObject $order, EventDomainObject $event, MoneyValue $amount): void
    {
        if (KioskSentinelEmail::isKioskSentinelEmail($order->getEmail())) {
            return;
        }

        $this->mailer
            ->to($order->getEmail())
            ->locale(CustomerLocale::get())
            ->send(new OrderRefunded(
                order: $order,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
                refundAmount: $amount
            ));
    }

    private function markOrderRefundPending(OrderDomainObject $order): OrderDomainObject
    {
        return $this->orderRepository->updateFromArray(
            id: $order->getId(),
            attributes: [
                OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_PENDING->name,
            ]
        );
    }

    /**
     * @throws ApiErrorException
     * @throws UnknownCurrencyException
     * @throws RefundNotPossibleException
     * @throws Throwable
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws NumberFormatException
     */
    private function refundOrder(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        $order = $this->fetchOrder($refundOrderDTO->event_id, $refundOrderDTO->order_id);
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($refundOrderDTO->event_id);

        $amount = MoneyValue::fromFloat($refundOrderDTO->amount, $order->getCurrency());

        $this->validateRefundability($order);
        $refundedAttendees = $this->refundedAttendees($order, $refundOrderDTO->attendee_ids);

        if ($refundOrderDTO->cancel_order) {
            $this->orderCancelService->cancelOrder($order);
        } else {
            foreach ($refundedAttendees as $attendee) {
                $this->partialEditAttendeeHandler->handle(new PartialEditAttendeeDTO(
                    attendee_id: $attendee->getId(),
                    event_id: $order->getEventId(),
                    first_name: null,
                    last_name: null,
                    email: null,
                    status: AttendeeStatus::CANCELLED->name,
                ));
            }
        }

        // Determine the correct Stripe platform for this refund
        // Use the platform that was used for the original payment
        $paymentPlatform = $order->getStripePayment()->getStripePlatformEnum();

        // Create Stripe client for the original payment's platform
        $stripeClient = $this->stripeClientFactory->createForPlatform($paymentPlatform);

        $this->refundService->refundPayment(
            amount: $amount,
            payment: $order->getStripePayment(),
            stripeClient: $stripeClient,
            metadata: array_filter([
                StripePaymentIntentRefundService::REFUND_SOURCE_KEY => StripePaymentIntentRefundService::REFUND_SOURCE_DASHBOARD,
                'picha_attendee_ids' => implode(',', $refundOrderDTO->attendee_ids),
            ]),
        );

        if ($refundOrderDTO->notify_buyer) {
            $this->notifyBuyer($order, $event, $amount);
        }

        return $this->markOrderRefundPending($order);
    }
}
