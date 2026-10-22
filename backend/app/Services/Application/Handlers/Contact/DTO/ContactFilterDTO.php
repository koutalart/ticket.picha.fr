<?php

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactFilterDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $query = null,
        public readonly ?int $eventId = null,
        public readonly ?int $tagId = null,
        public readonly bool $emailConsentOnly = false,
        public readonly bool $smsConsentOnly = false,
        public readonly bool $attendedOnly = false,
        public readonly bool $withPhoneOnly = false,
    ) {}

    public static function fromQuery(array $query): self
    {
        $flag = static fn (string $key) => filter_var($query[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        $id = static fn (string $key) => isset($query[$key]) && is_numeric($query[$key]) ? (int) $query[$key] : null;
        $search = isset($query['query']) ? trim((string) $query['query']) : '';

        return new self(
            query: $search === '' ? null : $search,
            eventId: $id('event_id'),
            tagId: $id('tag_id'),
            emailConsentOnly: $flag('email_consent'),
            smsConsentOnly: $flag('sms_consent'),
            attendedOnly: $flag('attended'),
            withPhoneOnly: $flag('has_phone'),
        );
    }
}
