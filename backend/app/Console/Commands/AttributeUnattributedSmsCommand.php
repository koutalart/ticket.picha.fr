<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Sms\DTO\SmsUnattributedBackfillItemDTO;
use HiEvents\Services\Domain\Sms\SmsUnattributedSmsService;
use Illuminate\Console\Command;
use Throwable;

class AttributeUnattributedSmsCommand extends Command
{
    protected $signature = 'sms:attribute-unattributed
        {--dry-run : Show what would be attributed without writing anything}
        {--days=30 : Attribution window, in days before each SMS was received}';

    protected $description = 'Give the SMS kept unattributed on a shared line to the organizer of the line who wrote last to the number, without any alert or automatic reply';

    public function __construct(
        private readonly SmsUnattributedSmsService $unattributedService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('--days must be a positive number of days.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $this->unattributedService->attributeFromHistory($days, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%sWindow: %d days. Unattributed SMS checked: %d.', $dryRun ? '[dry run] ' : '', $days, $result->scanned));

        if ($result->attributed !== []) {
            $this->table(
                ['Unattributed ID', 'Phone', 'Organizer', 'Last SMS sent at', 'sms_messages ID'],
                array_map(fn (SmsUnattributedBackfillItemDTO $item) => [
                    $item->unattributedId,
                    $item->phone,
                    $item->organizerId,
                    $item->lastSentAt,
                    $item->smsMessageId ?? '-',
                ], $result->attributed),
            );
        }

        $this->line(sprintf(
            '%s %d, left unattributed: %d, already in an inbox: %d.',
            $dryRun ? 'Would be attributed:' : 'Attributed:',
            count($result->attributed),
            count($result->unattributed),
            count($result->skippedIds),
        ));

        if ($result->skippedIds !== []) {
            $this->line('Already in an inbox (unattributed IDs): '.implode(', ', $result->skippedIds));
        }

        foreach ($result->maxMessageIdByOrganizer() as $organizerId => $maxId) {
            $this->line(sprintf('Organizer %d: highest sms_messages ID created = %d', $organizerId, $maxId));
        }

        if ($this->output->isVerbose() && ! $dryRun) {
            $ids = array_values(array_filter(array_map(fn (SmsUnattributedBackfillItemDTO $item) => $item->smsMessageId, $result->attributed)));
            $this->line('sms_messages IDs created: '.($ids === [] ? 'none' : implode(', ', $ids)));
        }

        return self::SUCCESS;
    }
}
