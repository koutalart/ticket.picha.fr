<?php

namespace HiEvents\Services\Domain\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactImportResultDTO extends BaseDataObject
{
    /**
     * @param  array<int, array{line: int, reason: string, values: string[]}>  $rejected_rows
     */
    public function __construct(
        public readonly ?int $import_id,
        public readonly int $created_count,
        public readonly int $updated_count,
        public readonly int $rejected_count,
        public readonly array $rejected_rows,
        public readonly bool $dry_run,
    ) {}
}
