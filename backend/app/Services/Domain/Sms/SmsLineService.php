<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsLineFormerMemberDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsLineFormerMemberDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsLineFormerMemberRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A line is the settings row holding the httpSMS connection (API key, number, webhook).
 * Other organizers can send with it by pointing their own settings to it with shared_line_settings_id.
 * An organizer detached from a line stays a former member of it during a transition window: the people it wrote
 * to from that line can still answer it there, and its replies to them still leave from that line.
 */
class SmsLineService
{
    public const DEFAULT_ATTRIBUTION_DAYS = 30;

    public const DEFAULT_TRANSITION_DAYS = 30;

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SmsSettingsService $settingsService,
        private readonly ConfigRepository $config,
        private readonly SmsLineFormerMemberRepositoryInterface $formerMemberRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * The settings to send with: the shared line for a member, the organizer's own settings otherwise.
     *
     * @throws SmsGatewayNotConfiguredException
     */
    public function resolveLine(OrganizerSmsSettingDomainObject $settings): OrganizerSmsSettingDomainObject
    {
        if ($settings->getSharedLineSettingsId() === null) {
            return $settings;
        }

        $line = $this->settingsService->findSharedLine($settings);
        if ($line === null) {
            throw new SmsGatewayNotConfiguredException(__('The shared SMS line of this organizer is not available'));
        }

        return $line;
    }

    /**
     * The line itself first, then the organizers attached to it.
     *
     * @return Collection<int, OrganizerSmsSettingDomainObject>
     */
    public function members(OrganizerSmsSettingDomainObject $line): Collection
    {
        return collect([$line])
            ->merge($this->settingsRepository->findWhere([
                OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $line->getId(),
            ]))
            ->values();
    }

    /**
     * The organizers detached from the line less than the transition window ago, and not attached to it again.
     *
     * @return Collection<int, OrganizerSmsSettingDomainObject>
     */
    public function formerMembers(OrganizerSmsSettingDomainObject $line): Collection
    {
        $organizerIds = $this->formerMemberRepository->findWhere([
            SmsLineFormerMemberDomainObjectAbstract::LINE_SETTINGS_ID => $line->getId(),
            [SmsLineFormerMemberDomainObjectAbstract::DETACHED_AT, '>=', $this->transitionStart()],
        ])
            ->map(fn (SmsLineFormerMemberDomainObject $former) => $former->getOrganizerId())
            ->reject(fn (int $organizerId) => $organizerId === $line->getOrganizerId())
            ->unique()
            ->values()
            ->all();

        if ($organizerIds === []) {
            return collect();
        }

        return $this->settingsRepository
            ->findWhereIn(OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID, $organizerIds)
            ->reject(fn (OrganizerSmsSettingDomainObject $settings) => $settings->getSharedLineSettingsId() === $line->getId())
            ->values();
    }

    /**
     * Everybody an SMS received on the line may belong to: its members, then its former members still in transition.
     *
     * @return Collection<int, OrganizerSmsSettingDomainObject>
     */
    public function candidates(OrganizerSmsSettingDomainObject $line): Collection
    {
        $members = $this->members($line);
        $memberOrganizerIds = $members->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())->all();

        return $members
            ->merge($this->formerMembers($line)->reject(
                fn (OrganizerSmsSettingDomainObject $former) => in_array($former->getOrganizerId(), $memberOrganizerIds, true),
            ))
            ->values();
    }

    /**
     * The organizers whose SMS to a number count before an automatic reply of this organizer: everybody on the line it
     * sends with, on the line of the conversation, and on the lines it left less than the transition window ago.
     *
     * @return array<int, int>
     *
     * @throws SmsGatewayNotConfiguredException
     */
    public function relatedOrganizerIds(OrganizerSmsSettingDomainObject $settings, ?int $conversationLineSettingsId = null): array
    {
        $line = $this->resolveLine($settings);

        $lineIds = $this->formerMemberRepository->findWhere([
            SmsLineFormerMemberDomainObjectAbstract::ORGANIZER_ID => $settings->getOrganizerId(),
            [SmsLineFormerMemberDomainObjectAbstract::DETACHED_AT, '>=', $this->transitionStart()],
        ])->map(fn (SmsLineFormerMemberDomainObject $former) => $former->getLineSettingsId());

        if ($conversationLineSettingsId !== null) {
            $lineIds->push($conversationLineSettingsId);
        }

        $lineIds = $lineIds->unique()->reject(fn (int $lineId) => $lineId === $line->getId())->values()->all();

        $lines = collect([$line]);
        if ($lineIds !== []) {
            $lines = $lines->merge($this->settingsRepository->findWhereIn(OrganizerSmsSettingDomainObjectAbstract::ID, $lineIds));
        }

        return $lines
            ->flatMap(fn (OrganizerSmsSettingDomainObject $relatedLine) => $this->candidates($relatedLine)
                ->map(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId()))
            ->push($settings->getOrganizerId())
            ->unique()
            ->values()
            ->all();
    }

    public function isShared(OrganizerSmsSettingDomainObject $settings): bool
    {
        return $settings->getSharedLineSettingsId() !== null
            || $this->settingsRepository->countWhere([
                OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $settings->getId(),
            ]) > 0
            || $this->formerMembers($settings)->isNotEmpty();
    }

    /**
     * The organizer an incoming SMS received on the line belongs to, among its members and its former members in
     * transition: the one who wrote last to this number from this line within the attribution window. When nobody did,
     * the only member of the line gets it; on a line shared by several members nobody does (null).
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $candidates
     */
    public function attributeInbound(OrganizerSmsSettingDomainObject $line, Collection $candidates, string $phone): ?OrganizerSmsSettingDomainObject
    {
        if ($candidates->count() <= 1) {
            return $candidates->first() ?? $line;
        }

        $organizerId = $this->messageRepository->findLastOutboundOrganizerId(
            $candidates->map(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId())->values()->all(),
            $phone,
            Carbon::now('UTC')->subDays($this->attributionWindowDays())->toDateTimeString(),
            $line->getId(),
        );

        $target = $organizerId === null
            ? null
            : $candidates->first(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId() === $organizerId);

        if ($target !== null) {
            return $target;
        }

        $members = $candidates->filter(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getId() === $line->getId()
            || $candidate->getSharedLineSettingsId() === $line->getId());

        return $members->count() === 1 ? $members->first() : null;
    }

    /**
     * The line a reply in the conversation leaves from: the line the conversation lives on when the organizer left it
     * less than the transition window ago, so the person stays in the same thread; otherwise its usual line.
     */
    public function replyLine(
        OrganizerSmsSettingDomainObject $settings,
        SmsConversationDomainObject $conversation,
        OrganizerSmsSettingDomainObject $defaultLine,
    ): OrganizerSmsSettingDomainObject {
        $lineId = $conversation->getLineSettingsId();
        if ($lineId === null || $lineId === $defaultLine->getId()) {
            return $defaultLine;
        }

        $isFormerMember = $this->formerMemberRepository->findFirstWhere([
            SmsLineFormerMemberDomainObjectAbstract::LINE_SETTINGS_ID => $lineId,
            SmsLineFormerMemberDomainObjectAbstract::ORGANIZER_ID => $settings->getOrganizerId(),
            [SmsLineFormerMemberDomainObjectAbstract::DETACHED_AT, '>=', $this->transitionStart()],
        ]) !== null;

        if (! $isFormerMember) {
            return $defaultLine;
        }

        /** @var OrganizerSmsSettingDomainObject|null $line */
        $line = $this->settingsRepository->findFirstWhere([OrganizerSmsSettingDomainObjectAbstract::ID => $lineId]);

        if ($line === null || $line->getSharedLineSettingsId() !== null || ! $line->getApiKey() || ! $line->getFromNumber()) {
            return $defaultLine;
        }

        return $line;
    }

    public function attributionWindowDays(): int
    {
        return max(1, (int) $this->config->get('services.httpsms.shared_line_attribution_days', self::DEFAULT_ATTRIBUTION_DAYS));
    }

    public function transitionWindowDays(): int
    {
        return max(1, (int) $this->config->get('services.httpsms.detached_line_transition_days', self::DEFAULT_TRANSITION_DAYS));
    }

    public function transitionStart(): string
    {
        return Carbon::now('UTC')->subDays($this->transitionWindowDays())->toDateTimeString();
    }

    /**
     * @throws SmsSharedLineException
     */
    public function attach(int $organizerId, int $lineOrganizerId): OrganizerSmsSettingDomainObject
    {
        if ($organizerId === $lineOrganizerId) {
            throw new SmsSharedLineException(__('An organizer cannot share its own line with itself'));
        }

        /** @var OrganizerSmsSettingDomainObject|null $line */
        $line = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $lineOrganizerId,
        ]);

        if ($line === null || ! $line->getApiKey() || ! $line->getFromNumber()) {
            throw new SmsSharedLineException(__('The line to share has no PICHA SMS API key or sending number'));
        }

        if ($line->getSharedLineSettingsId() !== null) {
            throw new SmsSharedLineException(__('This organizer already uses a shared line and cannot share it'));
        }

        $settings = $this->settingsService->getOrCreate($this->findOrganizer($organizerId)->getAccountId(), $organizerId);

        if ($this->settingsRepository->countWhere([
            OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $settings->getId(),
        ]) > 0) {
            throw new SmsSharedLineException(__('Other organizers use the line of this organizer'));
        }

        return $this->settingsRepository->updateFromArray($settings->getId(), [
            OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $line->getId(),
        ]);
    }

    /**
     * Detaches the organizer from the shared line and keeps it as a former member of that line for the transition window.
     * With an API key and a number, the organizer gets its own line in the same step, so it never stays without a line.
     *
     * @throws SmsSharedLineException
     * @throws InvalidSmsPhoneNumberException
     */
    public function detach(int $organizerId, ?string $apiKey = null, ?string $fromNumber = null): OrganizerSmsSettingDomainObject
    {
        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($settings === null || $settings->getSharedLineSettingsId() === null) {
            throw new SmsSharedLineException(__('This organizer does not use a shared line'));
        }

        $attributes = [OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => null];

        $apiKey = trim((string) $apiKey);
        $fromNumber = trim((string) $fromNumber);
        if (($apiKey === '') !== ($fromNumber === '')) {
            throw new SmsSharedLineException(__('Give both the PICHA SMS API key and the sending number of the new line, or none'));
        }

        if ($fromNumber !== '') {
            $normalized = SmsPhoneNormalizer::normalize($fromNumber);
            if ($normalized === null) {
                throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
            }

            $attributes[OrganizerSmsSettingDomainObjectAbstract::API_KEY] = $apiKey;
            $attributes[OrganizerSmsSettingDomainObjectAbstract::FROM_NUMBER] = $normalized;
        }

        $lineId = $settings->getSharedLineSettingsId();

        return $this->databaseManager->transaction(function () use ($settings, $organizerId, $lineId, $attributes) {
            $this->recordFormerMembership($lineId, $organizerId);

            $this->messageRepository->updateWhere(
                [SmsMessageDomainObjectAbstract::LINE_SETTINGS_ID => $lineId],
                [
                    SmsMessageDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                    [SmsMessageDomainObjectAbstract::LINE_SETTINGS_ID, 'null', null],
                ],
            );
            $this->conversationRepository->updateWhere(
                [SmsConversationDomainObjectAbstract::LINE_SETTINGS_ID => $lineId],
                [
                    SmsConversationDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                    [SmsConversationDomainObjectAbstract::LINE_SETTINGS_ID, 'null', null],
                ],
            );

            return $this->settingsRepository->updateFromArray($settings->getId(), $attributes);
        });
    }

    private function recordFormerMembership(int $lineId, int $organizerId): void
    {
        $where = [
            SmsLineFormerMemberDomainObjectAbstract::LINE_SETTINGS_ID => $lineId,
            SmsLineFormerMemberDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ];
        $detachedAt = Carbon::now('UTC')->toDateTimeString();

        if ($this->formerMemberRepository->findFirstWhere($where) !== null) {
            $this->formerMemberRepository->updateWhere([SmsLineFormerMemberDomainObjectAbstract::DETACHED_AT => $detachedAt], $where);

            return;
        }

        $this->formerMemberRepository->create([...$where, SmsLineFormerMemberDomainObjectAbstract::DETACHED_AT => $detachedAt]);
    }

    /**
     * @throws SmsSharedLineException
     */
    private function findOrganizer(int $organizerId): OrganizerDomainObject
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([OrganizerDomainObjectAbstract::ID => $organizerId]);
        if ($organizer === null) {
            throw new SmsSharedLineException(__('Organizer not found'));
        }

        return $organizer;
    }
}
