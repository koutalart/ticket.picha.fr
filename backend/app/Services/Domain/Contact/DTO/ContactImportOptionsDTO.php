<?php

namespace HiEvents\Services\Domain\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\ContactImportField;

class ContactImportOptionsDTO extends BaseDataObject
{
    /**
     * @param  array<int, ContactImportField>  $mapping  column index => field
     */
    public function __construct(
        public readonly int $organizerId,
        public readonly ?int $userId,
        public readonly string $fileName,
        public readonly array $mapping,
        public readonly ?string $tagName,
        public readonly bool $emailConsentDeclared,
        public readonly bool $smsConsentDeclared,
        public readonly string $defaultCountry = 'FR',
    ) {}
}
