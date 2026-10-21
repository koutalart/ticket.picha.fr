<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsOptOutSource;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;

class SmsOptOutService
{
    public function __construct(
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
    ) {}

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    public function addManual(int $organizerId, string $phone): SmsOptOutDomainObject
    {
        $normalized = SmsPhoneNormalizer::normalize($phone);
        if ($normalized === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        $where = [
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $normalized,
        ];

        /** @var SmsOptOutDomainObject|null $existing */
        $existing = $this->optOutRepository->findFirstWhere($where);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->optOutRepository->create([
                ...$where,
                SmsOptOutDomainObjectAbstract::SOURCE => SmsOptOutSource::MANUAL->name,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->optOutRepository->findFirstWhere($where);
        }
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function remove(int $organizerId, int $optOutId): void
    {
        $where = [
            SmsOptOutDomainObjectAbstract::ID => $optOutId,
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ];

        if ($this->optOutRepository->findFirstWhere($where) === null) {
            throw new ResourceNotFoundException(__('Opt-out not found'));
        }

        $this->optOutRepository->deleteWhere($where);
    }
}
