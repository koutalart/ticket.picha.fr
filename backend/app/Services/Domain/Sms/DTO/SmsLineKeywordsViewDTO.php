<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\SmsLineKeywordDomainObject;
use Illuminate\Support\Collection;

class SmsLineKeywordsViewDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, SmsLineKeywordDomainObject>  $keywords
     */
    public function __construct(
        public readonly bool $available,
        public readonly ?string $lineNumber,
        public readonly Collection $keywords,
    ) {}
}
