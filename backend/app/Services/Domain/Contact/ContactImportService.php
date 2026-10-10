<?php

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\ContactImportDomainObject;
use HiEvents\DomainObjects\Enums\ContactConsentSource;
use HiEvents\DomainObjects\Enums\ContactImportField;
use HiEvents\DomainObjects\Enums\ContactSource;
use HiEvents\DomainObjects\Status\ContactImportStatus;
use HiEvents\Helper\PhoneNumberNormalizer;
use HiEvents\Repository\Interfaces\ContactImportRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactTagAssignmentRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactTagRepositoryInterface;
use HiEvents\Services\Domain\Contact\DTO\ContactImportOptionsDTO;
use HiEvents\Services\Domain\Contact\DTO\ContactImportResultDTO;
use Illuminate\Database\DatabaseManager;

class ContactImportService
{
    private const MAX_REPORTED_REJECTIONS = 200;

    private const HEADER_ALIASES = [
        'FIRST_NAME' => ['prenom', 'prénom', 'first name', 'firstname', 'first_name', 'given name'],
        'LAST_NAME' => ['nom', 'last name', 'lastname', 'last_name', 'surname', 'family name', 'nom de famille'],
        'FULL_NAME' => ['nom complet', 'name', 'full name', 'fullname', 'contact', 'client', 'participant'],
        'EMAIL' => ['email', 'e-mail', 'mail', 'courriel', 'adresse email', 'adresse e-mail', 'email address'],
        'PHONE' => ['telephone', 'téléphone', 'tel', 'tél', 'phone', 'mobile', 'portable', 'sms', 'gsm', 'numero', 'numéro', 'whatsapp', 'landline_number', 'phone number'],
    ];

    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactImportRepositoryInterface $contactImportRepository,
        private readonly ContactTagRepositoryInterface $contactTagRepository,
        private readonly ContactTagAssignmentRepositoryInterface $contactTagAssignmentRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * Suggests a field per column from its header. When several columns match the same field
     * (a Brevo export has LANDLINE_NUMBER, SMS and WHATSAPP), the one holding the most values wins.
     *
     * @param  string[]  $headers
     * @param  array<int, string[]>  $rows
     * @return array<int, string> column index => ContactImportField name
     */
    public function suggestMapping(array $headers, array $rows = []): array
    {
        $candidates = [];

        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower(trim(str_replace('_', ' ', $header)));

            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (in_array($normalized, $aliases, true) || in_array(str_replace(' ', '_', $normalized), $aliases, true)) {
                    $filled = count(array_filter($rows, fn (array $row) => trim((string) ($row[$index] ?? '')) !== ''));
                    $candidates[$field][] = ['index' => $index, 'filled' => $filled];
                    break;
                }
            }
        }

        $mapping = array_fill_keys(array_keys($headers), ContactImportField::IGNORE->name);

        foreach ($candidates as $field => $columns) {
            usort($columns, fn (array $a, array $b) => [$b['filled'], $a['index']] <=> [$a['filled'], $b['index']]);
            $mapping[$columns[0]['index']] = $field;
        }

        return $mapping;
    }

    /**
     * @param  array<int, string[]>  $rows
     */
    public function import(array $rows, ContactImportOptionsDTO $options, bool $dryRun): ContactImportResultDTO
    {
        $index = $this->contactRepository->getIdentityIndex($options->organizerId);
        $parsed = $this->parseRows($rows, $options);

        $uniqueRows = [];
        foreach ($parsed['valid'] as $row) {
            $key = $row['email'] ?? 'phone:'.$row['phone'];
            if (! isset($uniqueRows[$key])) {
                $uniqueRows[$key] = $row;

                continue;
            }
            foreach (['phone', 'first_name', 'last_name'] as $field) {
                $uniqueRows[$key][$field] ??= $row[$field];
            }
            $uniqueRows[$key]['tags'] = array_values(array_unique([...$uniqueRows[$key]['tags'], ...$row['tags']]));
        }

        $toCreate = [];
        $toUpdate = [];
        foreach ($uniqueRows as $row) {
            $existing = $row['email'] !== null
                ? ($index['emails'][$row['email']] ?? null)
                : ($index['phones'][$row['phone']] ?? null);

            if ($existing !== null) {
                $toUpdate[] = ['existing' => $existing, 'row' => $row];
            } else {
                $toCreate[] = $row;
            }
        }

        $created = count($toCreate);
        $updated = count($toUpdate);

        if ($dryRun) {
            return new ContactImportResultDTO(
                import_id: null,
                created_count: $created,
                updated_count: $updated,
                rejected_count: count($parsed['rejected']),
                rejected_rows: array_slice($parsed['rejected'], 0, self::MAX_REPORTED_REJECTIONS),
                dry_run: true,
            );
        }

        $importId = $this->databaseManager->transaction(function () use ($options, $toCreate, $toUpdate, $created, $updated, $parsed) {
            /** @var ContactImportDomainObject $import */
            $import = $this->contactImportRepository->create([
                ContactImportDomainObject::ORGANIZER_ID => $options->organizerId,
                ContactImportDomainObject::USER_ID => $options->userId,
                ContactImportDomainObject::FILE_NAME => mb_substr($options->fileName, 0, 255),
                ContactImportDomainObject::STATUS => ContactImportStatus::PENDING->name,
                ContactImportDomainObject::COLUMN_MAPPING => array_map(fn (ContactImportField $field) => $field->name, $options->mapping),
                ContactImportDomainObject::TAG_NAME => $options->tagName,
                ContactImportDomainObject::EMAIL_CONSENT_DECLARED => $options->emailConsentDeclared,
                ContactImportDomainObject::SMS_CONSENT_DECLARED => $options->smsConsentDeclared,
            ]);

            $now = now()->toDateTimeString();
            $tagContactIds = [];

            foreach ($toCreate as $row) {
                $contact = $this->contactRepository->create([
                    ContactDomainObject::ORGANIZER_ID => $options->organizerId,
                    ContactDomainObject::EMAIL => $row['email'],
                    ContactDomainObject::PHONE => $row['phone'],
                    ContactDomainObject::FIRST_NAME => $row['first_name'],
                    ContactDomainObject::LAST_NAME => $row['last_name'],
                    ContactDomainObject::SOURCE => ContactSource::IMPORT->name,
                    ContactDomainObject::CONTACT_IMPORT_ID => $import->getId(),
                    ContactDomainObject::EMAIL_CONSENT_AT => $options->emailConsentDeclared && $row['email'] !== null ? $now : null,
                    ContactDomainObject::EMAIL_CONSENT_SOURCE => $options->emailConsentDeclared && $row['email'] !== null ? ContactConsentSource::IMPORT->name : null,
                    ContactDomainObject::SMS_CONSENT_AT => $options->smsConsentDeclared && $row['phone'] !== null ? $now : null,
                    ContactDomainObject::SMS_CONSENT_SOURCE => $options->smsConsentDeclared && $row['phone'] !== null ? ContactConsentSource::IMPORT->name : null,
                ]);

                $this->collectTags($tagContactIds, $contact->getId(), $row['tags'], $options->tagName);
            }

            foreach ($toUpdate as ['existing' => $existing, 'row' => $row]) {
                $attributes = array_filter([
                    ContactDomainObject::FIRST_NAME => $existing->first_name === null ? $row['first_name'] : null,
                    ContactDomainObject::LAST_NAME => $existing->last_name === null ? $row['last_name'] : null,
                    ContactDomainObject::PHONE => $existing->phone === null ? $row['phone'] : null,
                ]);

                if ($options->emailConsentDeclared && $existing->email_consent_at === null && $existing->email_unsubscribed_at === null) {
                    $attributes[ContactDomainObject::EMAIL_CONSENT_AT] = $now;
                    $attributes[ContactDomainObject::EMAIL_CONSENT_SOURCE] = ContactConsentSource::IMPORT->name;
                }

                $hasPhone = $existing->phone !== null || $row['phone'] !== null;
                if ($options->smsConsentDeclared && $hasPhone && $existing->sms_consent_at === null && $existing->sms_unsubscribed_at === null) {
                    $attributes[ContactDomainObject::SMS_CONSENT_AT] = $now;
                    $attributes[ContactDomainObject::SMS_CONSENT_SOURCE] = ContactConsentSource::IMPORT->name;
                }

                if ($attributes !== []) {
                    $this->contactRepository->updateFromArray($existing->id, $attributes);
                }

                $this->collectTags($tagContactIds, $existing->id, $row['tags'], $options->tagName);
            }

            foreach ($tagContactIds as $tagName => $contactIds) {
                $tag = $this->contactTagRepository->findOrCreateByName($options->organizerId, (string) $tagName);
                foreach (array_chunk($contactIds, 1000) as $chunk) {
                    $this->contactTagAssignmentRepository->assignToContacts($tag->getId(), $chunk);
                }
            }

            $this->contactImportRepository->updateFromArray($import->getId(), [
                ContactImportDomainObject::STATUS => ContactImportStatus::COMPLETED->name,
                ContactImportDomainObject::CREATED_COUNT => $created,
                ContactImportDomainObject::UPDATED_COUNT => $updated,
                ContactImportDomainObject::REJECTED_COUNT => count($parsed['rejected']),
                ContactImportDomainObject::REJECTED_ROWS => array_slice($parsed['rejected'], 0, self::MAX_REPORTED_REJECTIONS),
                ContactImportDomainObject::COMPLETED_AT => $now,
            ]);

            return $import->getId();
        });

        return new ContactImportResultDTO(
            import_id: $importId,
            created_count: $created,
            updated_count: $updated,
            rejected_count: count($parsed['rejected']),
            rejected_rows: array_slice($parsed['rejected'], 0, self::MAX_REPORTED_REJECTIONS),
            dry_run: false,
        );
    }

    public function revert(int $organizerId, int $contactImportId): ?int
    {
        $import = $this->contactImportRepository->findFirstWhere([
            ContactImportDomainObject::ID => $contactImportId,
            ContactImportDomainObject::ORGANIZER_ID => $organizerId,
        ]);

        if ($import === null) {
            return null;
        }

        return $this->databaseManager->transaction(function () use ($import) {
            $deleted = $this->contactRepository->deleteImportedContacts($import->getId());

            $this->contactImportRepository->updateFromArray($import->getId(), [
                ContactImportDomainObject::STATUS => ContactImportStatus::REVERTED->name,
                ContactImportDomainObject::REVERTED_AT => now()->toDateTimeString(),
            ]);

            return $deleted;
        });
    }

    /**
     * @param  array<int, string[]>  $rows
     * @return array{valid: array<int, array>, rejected: array<int, array{line: int, reason: string, values: string[]}>}
     */
    private function parseRows(array $rows, ContactImportOptionsDTO $options): array
    {
        $valid = [];
        $rejected = [];

        foreach ($rows as $position => $values) {
            $line = $position + 2;
            $row = ['email' => null, 'phone' => null, 'first_name' => null, 'last_name' => null, 'tags' => []];
            $rawEmail = null;
            $rawPhone = null;

            foreach ($options->mapping as $column => $field) {
                $value = trim((string) ($values[$column] ?? ''));
                if ($value === '') {
                    continue;
                }

                match ($field) {
                    ContactImportField::EMAIL => $rawEmail ??= $value,
                    ContactImportField::PHONE => $rawPhone ??= $value,
                    ContactImportField::FIRST_NAME => $row['first_name'] = mb_substr($value, 0, 255),
                    ContactImportField::LAST_NAME => $row['last_name'] = mb_substr($value, 0, 255),
                    ContactImportField::FULL_NAME => [$row['first_name'], $row['last_name']] = self::splitFullName($value),
                    ContactImportField::TAG => $row['tags'] = [...$row['tags'], ...self::splitTags($value)],
                    ContactImportField::IGNORE => null,
                };
            }

            if ($rawEmail !== null) {
                $email = ContactSyncService::normalizeEmail($rawEmail);
                if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $rejected[] = ['line' => $line, 'reason' => 'INVALID_EMAIL', 'values' => $values];

                    continue;
                }
                $row['email'] = $email;
            }

            if ($rawPhone !== null) {
                $row['phone'] = PhoneNumberNormalizer::normalize($rawPhone, $options->defaultCountry);
            }

            if ($row['email'] === null && $row['phone'] === null) {
                $rejected[] = [
                    'line' => $line,
                    'reason' => $rawPhone !== null ? 'INVALID_PHONE' : 'NO_EMAIL_OR_PHONE',
                    'values' => $values,
                ];

                continue;
            }

            $valid[] = $row;
        }

        return ['valid' => $valid, 'rejected' => $rejected];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];

        if (count($parts) < 2) {
            return [$parts[0] ?? null, null];
        }

        $upper = array_values(array_filter($parts, fn (string $part) => mb_strlen($part) > 1 && mb_strtoupper($part) === $part && mb_strtolower($part) !== $part));
        if ($upper !== [] && count($upper) < count($parts)) {
            $first = array_values(array_diff($parts, $upper));

            return [implode(' ', $first), implode(' ', $upper)];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }

    /**
     * @return string[]
     */
    private static function splitTags(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $tag) => mb_substr(trim($tag), 0, 100),
            preg_split('/[,;|]/', $value) ?: [],
        )));
    }

    /**
     * @param  array<string, int[]>  $tagContactIds
     * @param  string[]  $rowTags
     */
    private function collectTags(array &$tagContactIds, int $contactId, array $rowTags, ?string $importTag): void
    {
        $tags = $rowTags;
        if ($importTag !== null && trim($importTag) !== '') {
            $tags[] = trim($importTag);
        }

        foreach (array_unique($tags) as $tag) {
            $tagContactIds[$tag][] = $contactId;
        }
    }
}
