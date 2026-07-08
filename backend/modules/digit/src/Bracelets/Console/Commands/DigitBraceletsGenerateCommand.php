<?php

declare(strict_types=1);

namespace Digit\Bracelets\Console\Commands;

use Digit\Bracelets\Domain\Services\BraceletGenerationService;
use Digit\Bracelets\Security\EventSecurityKeyService;
use HiEvents\Models\Event;
use Illuminate\Console\Command;

/**
 * php artisan digit:bracelets:generate --event=<event_id> --quantity=93020 --batch-label="Somaroho 2026"
 *
 * A pure CLI command, not an HTTP endpoint - a 93,020-row generation run
 * has no HTTP timeout to worry about by construction. Always inserts in
 * chunks (see BraceletGenerationService), never one row at a time via
 * individual Eloquent saves.
 *
 * account_id is derived from the event itself (native Event::account_id) -
 * no need for the operator to pass it separately.
 *
 * Also ensures the event's HMAC signing key exists (creates it on first
 * use if missing) so that DigitBraceletsExportCommand can always compute
 * signatures immediately afterward.
 */
class DigitBraceletsGenerateCommand extends Command
{
    protected $signature = 'digit:bracelets:generate
        {--event= : Event ID to generate bracelets for}
        {--quantity= : Number of bracelets to generate}
        {--batch-label= : Optional label for this print batch, e.g. "Somaroho 2026"}';

    protected $description = 'Bulk-generate DIGIT Bracelets (QR wristbands) for an event.';

    public function __construct(
        private readonly BraceletGenerationService $generationService,
        private readonly EventSecurityKeyService $keyService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = (int) $this->option('event');
        $quantity = (int) $this->option('quantity');
        $batchLabel = $this->option('batch-label');

        if ($eventId <= 0 || $quantity <= 0) {
            $this->error('--event and --quantity are required and must be positive integers.');

            return self::FAILURE;
        }

        $event = Event::query()->find($eventId);

        if ($event === null) {
            $this->error("Event {$eventId} not found.");

            return self::FAILURE;
        }

        // Ensures a stable signing key exists before any bracelet is
        // printed. If one already exists, this is a no-op - it is never
        // regenerated here.
        $this->keyService->getOrCreateSecret($eventId);

        $this->info("Generating {$quantity} bracelet(s) for event {$eventId} ({$event->title})...");
        if ($batchLabel !== null) {
            $this->line("Batch label: {$batchLabel}");
        }

        $bar = $this->output->createProgressBar($quantity);
        $bar->start();

        // BraceletGenerationService itself chunks internally; we report
        // progress in the same chunk size by generating in a loop here
        // instead of a single opaque call, so the operator sees movement
        // during a run this large.
        $chunk = 1000;
        $remaining = $quantity;
        $created = 0;

        while ($remaining > 0) {
            $thisChunk = min($chunk, $remaining);
            $created += $this->generationService->generate($eventId, $event->account_id, $thisChunk, $batchLabel);
            $remaining -= $thisChunk;
            $bar->advance($thisChunk);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done. {$created} bracelet(s) created with status GENERATED.");
        $this->line('Next: php artisan digit:bracelets:export --event=' . $eventId);

        return self::SUCCESS;
    }
}
