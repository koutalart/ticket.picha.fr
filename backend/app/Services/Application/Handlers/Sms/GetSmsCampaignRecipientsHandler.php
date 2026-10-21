<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use Illuminate\Support\Collection;

class GetSmsCampaignRecipientsHandler
{
    public function __construct(
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
    ) {}

    /**
     * @return Collection<SmsCampaignRecipientDomainObject>
     *
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $campaignId): Collection
    {
        $campaign = $this->campaignRepository->findFirstWhere([
            SmsCampaignDomainObjectAbstract::ID => $campaignId,
            SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($campaign === null) {
            throw new ResourceNotFoundException(__('SMS campaign not found'));
        }

        $recipients = $this->recipientRepository
            ->loadRelation(new Relationship(QrLinkDomainObject::class, name: 'qr_link'))
            ->findWhere(
                where: [SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $campaignId],
                orderAndDirections: [new OrderAndDirection(SmsCampaignRecipientDomainObjectAbstract::ID)],
            );

        $totals = $this->qrLinkRepository->getOrderTotalsByQrLinkIds(
            $recipients->map(fn (SmsCampaignRecipientDomainObject $recipient) => $recipient->getQrLinkId())->filter()->values()->all()
        );

        return $recipients->each(function (SmsCampaignRecipientDomainObject $recipient) use ($totals) {
            $qrLink = $recipient->getQrLink();
            $qrLink?->setOrderTotals(
                $totals[$qrLink->getId()]['orders_count'] ?? 0,
                $totals[$qrLink->getId()]['revenue_gross'] ?? 0.0,
            );
        });
    }
}
