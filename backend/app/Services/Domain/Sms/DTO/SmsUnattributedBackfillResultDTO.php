<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsUnattributedBackfillResultDTO extends BaseDataObject
{
    /**
     * @param  array<int, SmsUnattributedBackfillItemDTO>  $attributed
     * @param  array<int, SmsUnattributedBackfillItemDTO>  $unattributed
     * @param  array<int, int>  $skippedIds  already in an organizer inbox
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $scanned,
        public readonly array $attributed,
        public readonly array $unattributed,
        public readonly array $skippedIds = [],
    ) {}

    /**
     * @return array<int, int> organizer id => highest sms_messages id created for it
     */
    public function maxMessageIdByOrganizer(): array
    {
        $max = [];
        foreach ($this->attributed as $item) {
            if ($item->organizerId === null || $item->smsMessageId === null) {
                continue;
            }
            $max[$item->organizerId] = max($max[$item->organizerId] ?? 0, $item->smsMessageId);
        }
        ksort($max);

        return $max;
    }
}
