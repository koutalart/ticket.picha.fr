<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\SmsCancellationConfirmationDomainObjectAbstract;
use HiEvents\DomainObjects\SmsCancellationConfirmationDomainObject;
use HiEvents\DomainObjects\Status\SmsCancellationConfirmationStatus;
use HiEvents\Models\SmsCancellationConfirmation;
use HiEvents\Repository\Interfaces\SmsCancellationConfirmationRepositoryInterface;

/**
 * @extends BaseRepository<SmsCancellationConfirmationDomainObject>
 */
class SmsCancellationConfirmationRepository extends BaseRepository implements SmsCancellationConfirmationRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsCancellationConfirmation::class;
    }

    public function getDomainObject(): string
    {
        return SmsCancellationConfirmationDomainObject::class;
    }

    public function createUnlessPhoneConfirmedSince(
        array $attributes,
        string $since,
        array $skippedAttributes,
    ): SmsCancellationConfirmationDomainObject {
        $eventId = $attributes[SmsCancellationConfirmationDomainObjectAbstract::EVENT_ID];
        $phone = $attributes[SmsCancellationConfirmationDomainObjectAbstract::PHONE];

        return $this->db->transaction(function () use ($attributes, $since, $skippedAttributes, $eventId, $phone) {
            $this->db->statement(
                'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
                ['sms_cancellation_confirmations', $eventId.':'.$phone],
            );

            $alreadyConfirmed = SmsCancellationConfirmation::query()
                ->where(SmsCancellationConfirmationDomainObjectAbstract::EVENT_ID, $eventId)
                ->where(SmsCancellationConfirmationDomainObjectAbstract::PHONE, $phone)
                ->whereIn(SmsCancellationConfirmationDomainObjectAbstract::STATUS, [
                    SmsCancellationConfirmationStatus::SENDING->name,
                    SmsCancellationConfirmationStatus::SENT->name,
                ])
                ->where(SmsCancellationConfirmationDomainObjectAbstract::CREATED_AT, '>=', $since)
                ->exists();

            /** @var SmsCancellationConfirmationDomainObject */
            return $this->create($alreadyConfirmed ? array_merge($attributes, $skippedAttributes) : $attributes);
        });
    }
}
