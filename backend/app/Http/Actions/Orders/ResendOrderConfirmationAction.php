<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ResendOrderConfirmationAction extends BaseAction
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Mailer $mailer,
        private readonly MailBuilderService $mailBuilderService,
    ) {}

    /**
     * @todo - move this to a handler
     */
    public function __invoke(int $eventId, int $orderId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(InvoiceDomainObject::class)
            ->findFirstWhere([
                OrderDomainObjectAbstract::EVENT_ID => $eventId,
                OrderDomainObjectAbstract::ID => $orderId,
            ]);

        if (! $order) {
            return $this->notFoundResponse();
        }

        if ($order->isOrderCompleted()) {
            if (KioskSentinelEmail::isKioskSentinelEmail($order->getEmail())) {
                return $this->errorResponse(
                    __('Ce participant n\'a pas encore d\'e-mail. Renseignez-le d\'abord.'),
                    ResponseCodes::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $event = $this->eventRepository
                ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
                ->loadRelation(new Relationship(EventSettingDomainObject::class))
                ->findById($order->getEventId());

            $mail = $this->mailBuilderService->buildOrderSummaryMail(
                $order,
                $event,
                $event->getEventSettings(),
                $event->getOrganizer(),
                $order->getLatestInvoice()
            );

            $this->mailer
                ->to($order->getEmail())
                ->locale($order->getLocale())
                ->send($mail);
        }

        return $this->noContentResponse();
    }
}
