<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Contact\SmsRecipientContactLinker;
use Illuminate\Console\Command;

class LinkSmsRecipientContactsCommand extends Command
{
    protected $signature = 'contacts:link-sms-recipients {--organizer= : Only link this organizer id}';

    protected $description = 'Attach every ?qr= link sent in an SMS campaign to the contact of its recipient (safe to run again)';

    public function handle(SmsRecipientContactLinker $linker): int
    {
        $organizerId = $this->option('organizer');
        $result = $linker->link($organizerId !== null ? (int) $organizerId : null);

        $this->info(sprintf(
            'QR links linked: %d, contacts created: %d, skipped: %d',
            $result->linkedQrLinks,
            $result->createdContacts,
            $result->skippedQrLinks,
        ));

        return self::SUCCESS;
    }
}
