<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsLineKeywordDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsLineKeywordDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsLineKeywordValidationException;
use HiEvents\Helper\SmsKeywordDetector;
use HiEvents\Helper\SmsLineKeywordNormalizer;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Helper\SmsTextSanitizer;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsLineKeywordRepositoryInterface;
use HiEvents\Services\Domain\Contact\SmsKeywordContactService;
use HiEvents\Services\Domain\Sms\DTO\CreateSmsLineKeywordDTO;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsLineKeywordMatchDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsLineKeywordsViewDTO;
use HiEvents\Services\Domain\Sms\DTO\UpdateSmsLineKeywordDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Keywords an organizer of a shared line gives its public (« Envoie JUNGLE au … »): an SMS received on the line whose
 * first word is one of them goes to that organizer, even when nobody of the line wrote to the number before.
 */
class SmsLineKeywordService
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 20;

    public const MAX_KEYWORDS_PER_ORGANIZER = 20;

    public const RESERVED = [
        'STOP', 'STOPSMS', 'ARRET', 'ARRETER', 'UNSUBSCRIBE', 'DESINSCRIRE', 'DESABONNER',
        'START', 'UNSTOP', 'AIDE', 'HELP', 'OUI', 'NON',
    ];

    /** « OUI R12 » answers a refund approval, « Q12 … » a chat question. */
    private const RESERVED_PATTERN = '/^[RQ][0-9]+$/';

    public function __construct(
        private readonly SmsLineKeywordRepositoryInterface $keywordRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsSettingsService $settingsService,
        private readonly SmsLineService $lineService,
        private readonly SmsInboundService $inboundService,
        private readonly SmsKeywordContactService $contactService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function view(int $organizerId): SmsLineKeywordsViewDTO
    {
        $line = $this->sharedLineOf($organizerId);

        return new SmsLineKeywordsViewDTO(
            available: $line !== null,
            lineNumber: $line?->getFromNumber(),
            keywords: $this->keywordRepository->findWhere(
                where: [SmsLineKeywordDomainObjectAbstract::ORGANIZER_ID => $organizerId],
                orderAndDirections: [new OrderAndDirection(SmsLineKeywordDomainObjectAbstract::KEYWORD)],
            ),
        );
    }

    /**
     * @throws SmsLineKeywordValidationException
     */
    public function create(CreateSmsLineKeywordDTO $dto): SmsLineKeywordDomainObject
    {
        $line = $this->sharedLineOf($dto->organizerId);
        if ($line === null) {
            throw new SmsLineKeywordValidationException(__('SMS keywords are only available to organizers on a shared SMS line'));
        }

        $keyword = self::validate($dto->keyword);

        if ($this->keywordRepository->countWhere([
            SmsLineKeywordDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
        ]) >= self::MAX_KEYWORDS_PER_ORGANIZER) {
            throw new SmsLineKeywordValidationException(
                __('You can have up to :max SMS keywords', ['max' => self::MAX_KEYWORDS_PER_ORGANIZER])
            );
        }

        $this->assertFree($line->getId(), $keyword, $dto->organizerId);

        try {
            return $this->keywordRepository->create([
                SmsLineKeywordDomainObjectAbstract::LINE_SETTINGS_ID => $line->getId(),
                SmsLineKeywordDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
                SmsLineKeywordDomainObjectAbstract::KEYWORD => $keyword,
                SmsLineKeywordDomainObjectAbstract::IS_ACTIVE => true,
                SmsLineKeywordDomainObjectAbstract::WELCOME_MESSAGE => self::cleanWelcome($dto->welcomeMessage),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new SmsLineKeywordValidationException(self::takenMessage($keyword));
        }
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function update(UpdateSmsLineKeywordDTO $dto): SmsLineKeywordDomainObject
    {
        $where = $this->ownedBy($dto->keywordId, $dto->organizerId);

        return $this->keywordRepository->updateByIdWhere(
            id: $dto->keywordId,
            attributes: [
                SmsLineKeywordDomainObjectAbstract::IS_ACTIVE => $dto->isActive,
                SmsLineKeywordDomainObjectAbstract::WELCOME_MESSAGE => self::cleanWelcome($dto->welcomeMessage),
            ],
            where: $where,
        );
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function delete(int $organizerId, int $keywordId): void
    {
        $this->keywordRepository->deleteWhere($this->ownedBy($keywordId, $organizerId));
    }

    /**
     * The organizer an SMS received on the line asks for with its first word, among the organizers of the line.
     * STOP and START are never keywords: they keep their usual handling.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $candidates
     */
    public function match(OrganizerSmsSettingDomainObject $line, Collection $candidates, string $content): ?SmsLineKeywordMatchDTO
    {
        if (SmsKeywordDetector::detect($content) !== null) {
            return null;
        }

        $word = SmsLineKeywordNormalizer::firstWord($content);
        if ($word === null || self::isReserved($word) || strlen($word) > self::MAX_LENGTH) {
            return null;
        }

        /** @var SmsLineKeywordDomainObject|null $keyword */
        $keyword = $this->keywordRepository->findFirstWhere([
            SmsLineKeywordDomainObjectAbstract::LINE_SETTINGS_ID => $line->getId(),
            SmsLineKeywordDomainObjectAbstract::KEYWORD => $word,
            SmsLineKeywordDomainObjectAbstract::IS_ACTIVE => true,
        ]);
        if ($keyword === null) {
            return null;
        }

        $settings = $candidates->first(
            fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId() === $keyword->getOrganizerId(),
        );

        return $settings === null ? null : new SmsLineKeywordMatchDTO(settings: $settings, keyword: $word);
    }

    /**
     * Stores the SMS, whole, in the conversation of the organizer of the keyword and adds the number to its contacts.
     * Nothing is sent back.
     *
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    public function recordInbound(OrganizerSmsSettingDomainObject $line, SmsLineKeywordMatchDTO $match, InboundSmsDTO $sms): ?SmsMessageDomainObject
    {
        $phone = SmsPhoneNormalizer::normalize($sms->contact);
        if ($phone === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        return $this->databaseManager->transaction(function () use ($line, $match, $sms, $phone) {
            $message = $this->inboundService->recordInbound(
                $match->settings,
                $sms,
                lineSettingsId: $line->getId(),
                lineKeyword: $match->keyword,
            );

            if ($message !== null) {
                $this->contactService->recordKeywordContact($match->settings->getOrganizerId(), $phone, $match->keyword);
            }

            return $message;
        });
    }

    /**
     * @throws SmsLineKeywordValidationException
     */
    public static function validate(string $raw): string
    {
        $words = SmsLineKeywordNormalizer::words($raw);

        if (count($words) !== 1) {
            throw new SmsLineKeywordValidationException(__('An SMS keyword is a single word, without spaces'));
        }

        $keyword = $words[0];
        $length = strlen($keyword);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || preg_match('/[A-Z]/', $keyword) !== 1) {
            throw new SmsLineKeywordValidationException(__('An SMS keyword has :min to :max letters or digits, with at least one letter', [
                'min' => self::MIN_LENGTH,
                'max' => self::MAX_LENGTH,
            ]));
        }

        if (self::isReserved($keyword)) {
            throw new SmsLineKeywordValidationException(__(':keyword is reserved and cannot be used as an SMS keyword', ['keyword' => $keyword]));
        }

        return $keyword;
    }

    public static function isReserved(string $keyword): bool
    {
        return in_array($keyword, self::RESERVED, true) || preg_match(self::RESERVED_PATTERN, $keyword) === 1;
    }

    /**
     * The shared line the organizer receives SMS on: the line it is attached to, or its own line when other organizers
     * share it. Null for an organizer without SMS settings or with a line of its own nobody shares.
     */
    private function sharedLineOf(int $organizerId): ?OrganizerSmsSettingDomainObject
    {
        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($settings === null) {
            return null;
        }

        if ($settings->getSharedLineSettingsId() !== null) {
            return $this->settingsService->findSharedLine($settings);
        }

        return $settings->getApiKey() && $settings->getFromNumber() && $this->lineService->isShared($settings) ? $settings : null;
    }

    /**
     * @throws SmsLineKeywordValidationException
     */
    private function assertFree(int $lineId, string $keyword, int $organizerId): void
    {
        /** @var SmsLineKeywordDomainObject|null $existing */
        $existing = $this->keywordRepository->findFirstWhere([
            SmsLineKeywordDomainObjectAbstract::LINE_SETTINGS_ID => $lineId,
            SmsLineKeywordDomainObjectAbstract::KEYWORD => $keyword,
        ]);

        if ($existing === null) {
            return;
        }

        throw new SmsLineKeywordValidationException($existing->getOrganizerId() === $organizerId
            ? __('You already have the SMS keyword :keyword', ['keyword' => $keyword])
            : self::takenMessage($keyword));
    }

    private static function takenMessage(string $keyword): string
    {
        return __('The SMS keyword :keyword is already used by another organizer on this line', ['keyword' => $keyword]);
    }

    private static function cleanWelcome(?string $welcomeMessage): ?string
    {
        if ($welcomeMessage === null) {
            return null;
        }

        $clean = SmsTextSanitizer::clean($welcomeMessage);

        return $clean === '' ? null : $clean;
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function ownedBy(int $keywordId, int $organizerId): array
    {
        $where = [
            SmsLineKeywordDomainObjectAbstract::ID => $keywordId,
            SmsLineKeywordDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ];

        if ($this->keywordRepository->findFirstWhere($where) === null) {
            throw new ResourceNotFoundException(__('SMS keyword not found'));
        }

        return $where;
    }
}
