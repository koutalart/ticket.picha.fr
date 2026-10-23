<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\OrganizerCalendarImportException;
use HiEvents\Services\Domain\OrganizerCalendar\DTO\ImportOrganizerCalendarDTO;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarImportService;
use Illuminate\Console\Command;

class ImportOrganizerCalendarCommand extends Command
{
    protected $signature = 'calendar:import
        {organizer : ID of the organizer owning the calendar}
        {slug : Public slug, the page is served at /calendrier/{slug}}
        {dir : Folder holding calendar.json, index.html and the media files}
        {--title= : Title used in the SMS (defaults to the current title, then "title" in calendar.json, then the slug)}
        {--phone= : Number receiving the access code, E.164 (defaults to the current number)}
        {--allow-missing : Import even when media named in calendar.json are missing (they are dropped)}';

    protected $description = 'Import or replace a private organizer calendar (content, page and media)';

    public function __construct(
        private readonly OrganizerCalendarImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->importService->import(new ImportOrganizerCalendarDTO(
                organizerId: (int) $this->argument('organizer'),
                slug: (string) $this->argument('slug'),
                directory: (string) $this->argument('dir'),
                title: $this->option('title') !== null ? (string) $this->option('title') : null,
                phone: $this->option('phone') !== null ? (string) $this->option('phone') : null,
                allowMissingMedia: (bool) $this->option('allow-missing'),
            ));
        } catch (OrganizerCalendarImportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Calendar "%s" %s (id %d): %d media, %.1f MB.',
            $result->title,
            $result->created ? 'created' : 'replaced',
            $result->calendarId,
            $result->mediaCount,
            $result->totalBytes / 1048576,
        ));
        $this->line($result->frontHtmlImported ? 'Page index.html imported.' : 'No index.html in the folder: the page /calendrier/'.$this->argument('slug').' stays empty.');

        if ($result->missingFiles !== []) {
            $this->warn('Missing media, removed from the content: '.implode(', ', $result->missingFiles));
        }

        if ($result->unusedFiles !== []) {
            $this->warn('Files not named in calendar.json, not imported: '.implode(', ', $result->unusedFiles));
        }

        return self::SUCCESS;
    }
}
