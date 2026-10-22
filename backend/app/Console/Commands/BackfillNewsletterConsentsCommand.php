<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Organizer\NewsletterConsentSubscriptionService;
use Illuminate\Console\Command;

class BackfillNewsletterConsentsCommand extends Command
{
    protected $signature = 'newsletter:backfill-consents
        {--organizer= : Only this organizer id}
        {--dry-run : Count the buyers that would be added without writing anything}';

    protected $description = 'Add to the organizer newsletter subscribers the buyers who ticked the marketing opt-in at registration';

    public function handle(NewsletterConsentSubscriptionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $organizerId = $this->option('organizer');

        $results = $organizerId !== null
            ? [(int) $organizerId => $service->subscribeOrganizer((int) $organizerId, dryRun: $dryRun)]
            : $service->subscribeAllOrganizers($dryRun);

        $verb = $dryRun ? 'would be added' : 'added';

        foreach ($results as $id => $count) {
            $this->line("Organizer {$id}: {$count} subscriber(s) {$verb}");
        }

        $this->info(sprintf('Total: %d subscriber(s) %s%s', array_sum($results), $verb, $dryRun ? ' (dry run, nothing written)' : ''));

        return self::SUCCESS;
    }
}
