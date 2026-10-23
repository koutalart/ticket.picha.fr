<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsContactSyncResultDTO;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class SmsContactSyncService
{
    public const MANAGED_PROPERTY = 'picha_sync';

    public const ORGANIZERS_PROPERTY = 'picha_organizers';

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 500;

    private const CREATE_BATCH_SIZE = 100;

    private const MAX_NAME_LENGTH = 150;

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
        private readonly HttpSmsClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Organizers using a line shared by another organizer are skipped: the contacts of a shared httpSMS account
     * are common to every organizer of the line, so only the line owner customers are written there.
     */
    public function syncAll(bool $dryRun = false): SmsContactSyncResultDTO
    {
        /** @var Collection<int, OrganizerSmsSettingDomainObject> $settings */
        $settings = $this->settingsRepository
            ->findWhere([[OrganizerSmsSettingDomainObjectAbstract::API_KEY, 'not null', null]])
            ->filter(fn (OrganizerSmsSettingDomainObject $setting) => trim((string) $setting->getApiKey()) !== '')
            ->filter(fn (OrganizerSmsSettingDomainObject $setting) => $setting->getSharedLineSettingsId() === null);

        if ($settings->isEmpty()) {
            return new SmsContactSyncResultDTO;
        }

        $organizerNames = $this->organizerRepository
            ->findWhereIn('id', $settings->map->getOrganizerId()->unique()->values()->all())
            ->mapWithKeys(fn (OrganizerDomainObject $organizer) => [$organizer->getId() => $organizer->getName()]);

        $result = new SmsContactSyncResultDTO;

        foreach ($settings->groupBy(fn (OrganizerSmsSettingDomainObject $setting) => trim((string) $setting->getApiKey())) as $apiKey => $group) {
            $organizerIds = $group->map->getOrganizerId()->unique()->values();

            try {
                $result = $result->add($this->syncAccount((string) $apiKey, $organizerIds, $organizerNames, $dryRun));
            } catch (SmsGatewayException $exception) {
                $this->logger->warning('SMS contact sync failed for an httpSMS account', [
                    'organizer_ids' => $organizerIds->all(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, int>  $organizerIds
     * @param  Collection<int, string>  $organizerNames
     *
     * @throws SmsGatewayException
     */
    private function syncAccount(string $apiKey, Collection $organizerIds, Collection $organizerNames, bool $dryRun): SmsContactSyncResultDTO
    {
        $desired = $this->buildDesiredContacts($organizerIds, $organizerNames, $organizerIds->count() > 1);
        [$managed, $manualPhones] = $this->loadExistingContacts($apiKey);

        $toCreate = [];
        $updated = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($desired as $phone => $contact) {
            if (isset($manualPhones[$phone])) {
                continue;
            }

            $existing = $managed[$phone] ?? null;
            if ($existing === null) {
                $toCreate[] = $contact;

                continue;
            }

            if ($this->isUpToDate($existing, $contact)) {
                $unchanged++;

                continue;
            }

            if ($dryRun) {
                $updated++;

                continue;
            }

            try {
                $this->client->updateContact($apiKey, (string) $existing['id'], $contact);
                $updated++;
            } catch (SmsGatewayException $exception) {
                $this->logger->warning('SMS contact update rejected', ['error' => $exception->getMessage()]);
                $failed++;
            }
        }

        [$created, $createFailed] = $dryRun ? [count($toCreate), 0] : $this->createContacts($apiKey, $toCreate);

        return new SmsContactSyncResultDTO(
            accounts: 1,
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            failed: $failed + $createFailed,
        );
    }

    /**
     * @param  Collection<int, int>  $organizerIds
     * @param  Collection<int, string>  $organizerNames
     * @return array<string, array<string, mixed>>
     */
    private function buildDesiredContacts(Collection $organizerIds, Collection $organizerNames, bool $labelOrganizers): array
    {
        $byPhone = [];
        $conversationNames = [];

        foreach ($organizerIds as $organizerId) {
            foreach ($this->directoryRepository->findConversationNames($organizerId) as $rawPhone => $name) {
                $phone = self::canonicalPhone(SmsPhoneNormalizer::normalize((string) $rawPhone));
                if ($phone !== null) {
                    $conversationNames[$phone] ??= $name;
                    $byPhone[$phone] ??= ['name' => $name, 'email' => null, 'organizers' => []];
                    $byPhone[$phone]['organizers'][$organizerId] = $organizerNames->get($organizerId, '');
                }
            }
        }

        foreach ($organizerIds as $organizerId) {
            foreach ($this->directoryRepository->findCustomersWithPhone($organizerId) as $customer) {
                $phone = self::canonicalPhone(SmsPhoneNormalizer::normalize($customer->phone));
                $name = $conversationNames[$phone ?? ''] ?? trim(($customer->firstName ?? '').' '.($customer->lastName ?? ''));

                if ($phone === null || $name === '') {
                    continue;
                }

                $byPhone[$phone] ??= ['name' => $name, 'email' => null, 'organizers' => []];
                $byPhone[$phone]['email'] ??= $customer->email;
                $byPhone[$phone]['organizers'][$organizerId] = $organizerNames->get($organizerId, '');
            }
        }

        $contacts = [];
        foreach ($byPhone as $phone => $entry) {
            $organizers = $entry['organizers'];
            ksort($organizers);

            $name = $entry['name'];
            $labels = array_filter($organizers, fn (string $label) => $label !== '');
            if ($labelOrganizers && $labels !== []) {
                $name .= ' ('.implode(', ', $labels).')';
            }

            $email = strtolower(trim((string) $entry['email']));

            $contacts[$phone] = [
                'name' => mb_substr($name, 0, self::MAX_NAME_LENGTH),
                'phone_numbers' => [$phone],
                'emails' => filter_var($email, FILTER_VALIDATE_EMAIL) ? [$email] : [],
                'properties' => [
                    self::MANAGED_PROPERTY => '1',
                    self::ORGANIZERS_PROPERTY => implode(',', array_keys($organizers)),
                ],
            ];
        }

        return $contacts;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, true>}
     *
     * @throws SmsGatewayException
     */
    private function loadExistingContacts(string $apiKey): array
    {
        $managed = [];
        $manualPhones = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $contacts = $this->client->listContacts($apiKey, $page * self::PAGE_SIZE, self::PAGE_SIZE);

            foreach ($contacts as $contact) {
                $isManaged = (($contact['properties'][self::MANAGED_PROPERTY] ?? null) === '1');

                foreach ((array) ($contact['phone_numbers'] ?? []) as $storedPhone) {
                    $phone = self::canonicalPhone((string) $storedPhone);
                    if ($phone === null) {
                        continue;
                    }

                    if ($isManaged) {
                        $managed[$phone] ??= $contact;
                    } else {
                        $manualPhones[$phone] = true;
                    }
                }
            }

            if (count($contacts) < self::PAGE_SIZE) {
                break;
            }
        }

        return [$managed, $manualPhones];
    }

    private static function canonicalPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return preg_replace('/^\+(33|262)0/', '+$1', $phone) ?? $phone;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $desired
     */
    private function isUpToDate(array $existing, array $desired): bool
    {
        $existingEmails = array_values((array) ($existing['emails'] ?? []));
        $existingOrganizers = (string) ($existing['properties'][self::ORGANIZERS_PROPERTY] ?? '');

        return ($existing['name'] ?? null) === $desired['name']
            && $existingEmails === $desired['emails']
            && $existingOrganizers === $desired['properties'][self::ORGANIZERS_PROPERTY];
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @return array{0: int, 1: int}
     *
     * @throws SmsGatewayException
     */
    private function createContacts(string $apiKey, array $contacts): array
    {
        $created = 0;
        $failed = 0;

        foreach (array_chunk($contacts, self::CREATE_BATCH_SIZE) as $batch) {
            try {
                $this->client->createContacts($apiKey, $batch);
                $created += count($batch);

                continue;
            } catch (SmsGatewayException $exception) {
                if ($exception->getCode() !== 422 && $exception->getCode() !== 400) {
                    throw $exception;
                }
            }

            foreach ($batch as $contact) {
                try {
                    $this->client->createContacts($apiKey, [$contact]);
                    $created++;
                } catch (SmsGatewayException $exception) {
                    $this->logger->warning('SMS contact rejected by httpSMS', ['error' => $exception->getMessage()]);
                    $failed++;
                }
            }
        }

        return [$created, $failed];
    }
}
