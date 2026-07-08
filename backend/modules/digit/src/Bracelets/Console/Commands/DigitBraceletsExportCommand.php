<?php

declare(strict_types=1);

namespace Digit\Bracelets\Console\Commands;

use Digit\Bracelets\Domain\Models\DigitBracelet;
use Digit\Bracelets\Domain\Services\BraceletSignatureService;
use Digit\Bracelets\Security\EventSecurityKeyService;
use Illuminate\Console\Command;

/**
 * php artisan digit:bracelets:export --event=<event_id> [--output=path.csv]
 *
 * Produces a CSV with columns: code, signature, batch_label, event_id.
 * No QR image is generated here (see design docs: Hi.Events itself has
 * zero backend QR generation, it's all client-side) - the printer/vendor
 * is responsible for rendering a QR that encodes:
 *
 *     DGT1.{code}.{signature}
 *
 * i.e. the version prefix + these two exported columns joined by ".".
 *
 * The signature column is recomputed here, on every export, from the
 * event's stored secret - it is never read from a stored value anywhere,
 * consistent with "signature is derived, never persisted".
 *
 * Streams directly to the output file via a DB cursor rather than loading
 * all rows into memory - safe at the ~93,020-row Somaroho scale.
 */
class DigitBraceletsExportCommand extends Command
{
    protected $signature = 'digit:bracelets:export
        {--event= : Event ID to export bracelets for}
        {--output= : Output CSV path (default: storage/app/digit-bracelets-export-{event}.csv)}';

    protected $description = 'Export DIGIT Bracelets (code + recomputed signature) as CSV for the printer.';

    public function __construct(
        private readonly EventSecurityKeyService $keyService,
        private readonly BraceletSignatureService $signatureService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = (int) $this->option('event');

        if ($eventId <= 0) {
            $this->error('--event is required and must be a positive integer.');

            return self::FAILURE;
        }

        $secret = $this->keyService->findSecret($eventId);

        if ($secret === null) {
            $this->error("No security key found for event {$eventId}. Run digit:bracelets:generate first.");

            return self::FAILURE;
        }

        $total = DigitBracelet::query()->where('event_id', $eventId)->count();

        if ($total === 0) {
            $this->warn("No bracelets found for event {$eventId}.");

            return self::SUCCESS;
        }

        $outputPath = $this->option('output')
            ?? storage_path("app/digit-bracelets-export-{$eventId}.csv");

        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            $this->error("Could not open {$outputPath} for writing.");

            return self::FAILURE;
        }

        fputcsv($handle, ['code', 'signature', 'batch_label', 'event_id']);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        DigitBracelet::query()
            ->where('event_id', $eventId)
            ->orderBy('id')
            ->select(['code', 'batch_label', 'event_id'])
            ->cursor()
            ->each(function (DigitBracelet $bracelet) use ($handle, $secret, $bar) {
                fputcsv($handle, [
                    $bracelet->code,
                    $this->signatureService->sign($bracelet->code, $secret),
                    $bracelet->batch_label,
                    $bracelet->event_id,
                ]);
                $bar->advance();
            });

        $bar->finish();
        $this->newLine(2);
        fclose($handle);

        $this->info("Exported {$total} bracelet(s) to {$outputPath}");

        return self::SUCCESS;
    }
}
