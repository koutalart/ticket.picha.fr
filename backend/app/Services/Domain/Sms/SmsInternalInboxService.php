<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsInternalInboxMoveDTO;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The internal « Anicka » organizer of the platform team: the SMS the team sends to the shared line land there, never with
 * a client organizer.
 */
class SmsInternalInboxService
{
    public const ORGANIZER_NAME = 'Anicka';

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsLineService $lineService,
        private readonly SmsConversationService $conversationService,
        private readonly CreateOrganizerHandler $createOrganizerHandler,
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws Throwable
     */
    public function findOrCreateOrganizer(int $accountId): OrganizerDomainObject
    {
        /** @var OrganizerDomainObject|null $existing */
        $existing = $this->organizerRepository->findFirstWhere([
            OrganizerDomainObjectAbstract::ACCOUNT_ID => $accountId,
            OrganizerDomainObjectAbstract::NAME => self::ORGANIZER_NAME,
        ]);

        return $existing ?? $this->createOrganizerHandler->handle(new CreateOrganizerDTO(
            name: self::ORGANIZER_NAME,
            email: (string) $this->config->get('mail.from.address'),
            account_id: $accountId,
            timezone: 'Indian/Mayotte',
            currency: 'EUR',
        ));
    }

    /**
     * @throws SmsSharedLineException
     */
    public function attachToLine(int $organizerId, int $lineSettingsId): OrganizerSmsSettingDomainObject
    {
        $line = $this->findLine($lineSettingsId);

        /** @var OrganizerSmsSettingDomainObject|null $current */
        $current = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($current?->getSharedLineSettingsId() === $line->getId()) {
            return $current;
        }

        return $this->lineService->attach($organizerId, $line->getOrganizerId());
    }

    /**
     * The conversations of the team numbers filed with another organizer of the line, merged into the conversation of the
     * internal organizer for that number unless it is a dry run.
     *
     * @return Collection<int, SmsInternalInboxMoveDTO>
     *
     * @throws SmsSharedLineException
     */
    public function moveHistory(OrganizerSmsSettingDomainObject $internal, int $lineSettingsId, bool $dryRun): Collection
    {
        $line = $this->findLine($lineSettingsId);
        $organizerIds = $this->lineService->members($line)
            ->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())
            ->reject(fn (int $organizerId) => $organizerId === $internal->getOrganizerId())
            ->unique()
            ->values()
            ->all();

        if ($organizerIds === []) {
            return collect();
        }

        return collect($this->lineService->internalPhones())
            ->flatMap(fn (string $phone) => $this->conversationRepository->findWhereIn(
                SmsConversationDomainObjectAbstract::ORGANIZER_ID,
                $organizerIds,
                [SmsConversationDomainObjectAbstract::PHONE => $phone],
            ))
            ->map(fn (SmsConversationDomainObject $conversation) => $this->move($internal, $conversation, $dryRun))
            ->values();
    }

    private function move(OrganizerSmsSettingDomainObject $internal, SmsConversationDomainObject $conversation, bool $dryRun): SmsInternalInboxMoveDTO
    {
        $messages = $this->messageRepository->countWhere([
            SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
        ]);

        $targetId = null;
        if (! $dryRun) {
            $targetId = $this->databaseManager->transaction(function () use ($internal, $conversation) {
                $target = $this->conversationService->findOrCreate($internal, $conversation->getPhone());
                $this->conversationRepository->mergeInto($conversation->getId(), $target->getId());
                $this->messageRepository->updateWhere(
                    [SmsMessageDomainObjectAbstract::ORGANIZER_ID => $internal->getOrganizerId()],
                    [SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $target->getId()],
                );

                return $target->getId();
            });
        }

        return new SmsInternalInboxMoveDTO(
            conversationId: $conversation->getId(),
            organizerId: $conversation->getOrganizerId(),
            phone: $conversation->getPhone(),
            messages: $messages,
            targetConversationId: $targetId,
        );
    }

    /**
     * @throws SmsSharedLineException
     */
    private function findLine(int $lineSettingsId): OrganizerSmsSettingDomainObject
    {
        /** @var OrganizerSmsSettingDomainObject|null $line */
        $line = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ID => $lineSettingsId,
        ]);

        if ($line === null || $line->getSharedLineSettingsId() !== null) {
            throw new SmsSharedLineException(__('The line to share has no PICHA SMS API key or sending number'));
        }

        return $line;
    }
}
