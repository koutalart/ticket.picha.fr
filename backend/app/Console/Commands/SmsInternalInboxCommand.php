<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Services\Domain\Sms\DTO\SmsInternalInboxMoveDTO;
use HiEvents\Services\Domain\Sms\SmsInternalInboxService;
use HiEvents\Services\Domain\Sms\SmsLineService;
use Illuminate\Console\Command;
use Throwable;

class SmsInternalInboxCommand extends Command
{
    protected $signature = 'sms:internal-inbox
        {account : ID of the account that holds the internal « Anicka » organizer}
        {--line=1 : ID of the SMS settings of the shared line}
        {--move-history : Move the conversations of the team numbers filed with client organizers into « Anicka »}
        {--force : Apply the move (dry run by default)}';

    protected $description = 'Create the internal « Anicka » organizer, attach it to the shared line, and move the team SMS history into it';

    public function __construct(
        private readonly SmsInternalInboxService $inboxService,
        private readonly SmsLineService $lineService,
    ) {
        parent::__construct();
    }

    /**
     * @throws Throwable
     */
    public function handle(): int
    {
        $lineSettingsId = (int) $this->option('line');

        try {
            $organizer = $this->inboxService->findOrCreateOrganizer((int) $this->argument('account'));
            $settings = $this->inboxService->attachToLine($organizer->getId(), $lineSettingsId);
        } catch (SmsSharedLineException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Internal organizer « %s » %d is on line settings %d.', $organizer->getName(), $organizer->getId(), $lineSettingsId));
        $this->line('INTERNAL_ORGANIZER_ID='.$organizer->getId());
        $this->line('Add to the backend environment: HTTPSMS_INTERNAL_ORGANIZER_ID='.$organizer->getId());

        if (! $this->option('move-history')) {
            return self::SUCCESS;
        }

        if ($this->lineService->internalPhones() === []) {
            $this->error('HTTPSMS_INTERNAL_PHONES is empty: nothing to move.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('force');
        $moves = $this->inboxService->moveHistory($settings, $lineSettingsId, $dryRun);

        $this->table(
            ['Conversation', 'Organizer', 'Phone', 'Messages', 'Moved into'],
            $moves->map(fn (SmsInternalInboxMoveDTO $move) => [
                $move->conversationId,
                $move->organizerId,
                $move->phone,
                $move->messages,
                $move->targetConversationId ?? '-',
            ])->all(),
        );

        $this->info(sprintf(
            '%s %d conversation(s), %d message(s).',
            $dryRun ? 'Dry run, would move' : 'Moved',
            $moves->count(),
            $moves->sum(fn (SmsInternalInboxMoveDTO $move) => $move->messages),
        ));

        return self::SUCCESS;
    }
}
