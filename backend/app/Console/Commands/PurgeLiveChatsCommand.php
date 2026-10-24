<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;

/**
 * Deletes the web chats where the visitor left no contact details once the retention period is over (RGPD).
 * Chats handed over to the organizer stay: their transcript is also part of the SMS conversation or of the alert e-mail.
 */
class PurgeLiveChatsCommand extends Command
{
    protected $signature = 'live-chat:purge';

    protected $description = 'Delete web chats without contact details older than the retention period';

    public function handle(LiveChatSessionRepositoryInterface $sessionRepository, Repository $config): int
    {
        $days = max(1, (int) $config->get('services.live_chat.retention_days', 90));
        $deleted = $sessionRepository->deleteWithoutContactBefore(now()->subDays($days)->toDateTimeString());

        $this->info(sprintf('%d web chat(s) deleted.', $deleted));

        return self::SUCCESS;
    }
}
