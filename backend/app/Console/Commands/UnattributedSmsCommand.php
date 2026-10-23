<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Services\Domain\Sms\SmsUnattributedSmsService;
use Illuminate\Console\Command;

class UnattributedSmsCommand extends Command
{
    protected $signature = 'sms:unattributed
        {--assign= : ID of the unattributed SMS to move into an organizer inbox}
        {--organizer= : ID of the organizer receiving the SMS, it must use the line that received it}
        {--limit=50 : Number of SMS to list}';

    protected $description = 'List the SMS received on a shared line that no organizer wrote to recently, or assign one to an organizer';

    public function __construct(
        private readonly SmsUnattributedSmsService $unattributedService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('assign') !== null) {
            return $this->assign();
        }

        $messages = $this->unattributedService->listPending(max(1, (int) $this->option('limit')));

        if ($messages->isEmpty()) {
            $this->info('No unattributed SMS.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Line (settings ID)', 'Phone', 'Received at', 'STOP applied to all', 'Content'],
            $messages->map(fn (SmsUnattributedMessageDomainObject $message) => [
                $message->getId(),
                $message->getLineSettingsId(),
                $message->getPhone(),
                $message->getProviderTimestamp() ?? $message->getCreatedAt(),
                $message->getOptOutApplied() ? 'yes' : 'no',
                mb_strimwidth(str_replace(["\r", "\n"], ' ', $message->getContent()), 0, 80, '...'),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function assign(): int
    {
        $organizerId = (int) $this->option('organizer');
        if ($organizerId <= 0) {
            $this->error('Pass the organizer with --organizer=ID.');

            return self::FAILURE;
        }

        try {
            $message = $this->unattributedService->assign((int) $this->option('assign'), $organizerId);
        } catch (ResourceNotFoundException|SmsSharedLineException|InvalidSmsPhoneNumberException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'SMS assigned to organizer %d (conversation %d, message %d).',
            $organizerId,
            $message->getSmsConversationId(),
            $message->getId(),
        ));

        return self::SUCCESS;
    }
}
