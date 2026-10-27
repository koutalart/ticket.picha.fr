<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use Illuminate\Console\Command;

class PurgeSocialMediaFilesCommand extends Command
{
    protected $signature = 'social:purge-media';

    protected $description = 'Delete the photos and videos imported for Facebook / Instagram 30 days after their publication, and abandoned imports';

    public function handle(SocialMediaFileService $mediaFileService): int
    {
        $this->info(sprintf('%d imported media deleted.', $mediaFileService->purgeDue()));

        return self::SUCCESS;
    }
}
