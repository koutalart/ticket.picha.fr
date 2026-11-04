<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\OrganizerSection;
use HiEvents\Exceptions\InvalidOrganizerSectionException;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Services\Domain\Organizer\OrganizerSectionsService;
use Illuminate\Console\Command;

class OrganizerSectionsCommand extends Command
{
    protected $signature = 'organizer:sections
        {organizer : Organizer ID}
        {--only= : Comma separated list of sections to show (dashboard, events, contacts, reports, qr-codes, sms, publications)}
        {--all : Show every section again (default behaviour)}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Limit the sections an organizer sees in its management area (Settings always stays visible)';

    public function __construct(
        private readonly OrganizerSectionsService $sectionsService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $only = $this->option('only');
        $all = (bool) $this->option('all');

        if ($only !== null && $all) {
            $this->error(__('Use either --only or --all, not both.'));

            return self::FAILURE;
        }

        try {
            $organizer = $this->sectionsService->findOrganizer((int) $this->argument('organizer'));
        } catch (OrganizerNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $current = OrganizerSectionsService::sectionsOf($organizer);

        if ($only === null && ! $all) {
            $this->info(sprintf('"%s" (#%d): %s', $organizer->getName(), $organizer->getId(), $this->describe($current)));
            $this->line(__('Valid sections: :valid', ['valid' => OrganizerSection::valuesCsv(', ')]));

            return self::SUCCESS;
        }

        try {
            $sections = $all ? null : $this->sectionsService->normalize(explode(',', (string) $only));
        } catch (InvalidOrganizerSectionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $summary = sprintf(
            '"%s" (#%d): %s -> %s',
            $organizer->getName(),
            $organizer->getId(),
            $this->describe($current),
            $this->describe($sections),
        );

        if ($this->option('dry-run')) {
            $this->info(__('[dry-run] :summary', ['summary' => $summary]));

            return self::SUCCESS;
        }

        $this->sectionsService->setSections($organizer->getId(), $sections);
        $this->info($summary);

        return self::SUCCESS;
    }

    private function describe(?array $sections): string
    {
        return $sections === null
            ? __('all sections')
            : implode(', ', $sections).' + settings';
    }
}
