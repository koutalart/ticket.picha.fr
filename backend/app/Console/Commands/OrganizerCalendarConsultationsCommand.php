<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\OrganizerCalendarConsultationDomainObject;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Helper\UserAgentSummarizer;
use HiEvents\Repository\DTO\OrganizerCalendarVisitorTotalDTO;
use HiEvents\Repository\Interfaces\OrganizerCalendarConsultationRepositoryInterface;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessModeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Who opened a private calendar and when, in Mayotte time. Visits are logged at most once every 10 minutes per device.
 */
class OrganizerCalendarConsultationsCommand extends Command
{
    private const TIMEZONE = 'Indian/Mayotte';

    protected $signature = 'calendar:consultations
        {slug : Slug of the calendar}
        {--limit=50 : Number of lines to show}
        {--since= : Only since this date (Mayotte time), e.g. "2026-10-24" or "2026-10-24 18:00" or "3 days ago"}';

    protected $description = 'Show who opened a private organizer calendar (first name, device, IP, browser), with a total per first name';

    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarConsultationRepositoryInterface $consultationRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $calendar = $this->calendarResolver->findBySlug((string) $this->argument('slug'));
        } catch (OrganizerCalendarNotFoundException) {
            $this->error('No calendar with this slug.');

            return self::FAILURE;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $since = null;

        if ($this->option('since') !== null && $this->option('since') !== '') {
            try {
                $since = Carbon::parse((string) $this->option('since'), self::TIMEZONE)->utc()->toDateTimeString();
            } catch (Throwable) {
                $this->error('Invalid --since date.');

                return self::FAILURE;
            }
        }

        $this->info(sprintf(
            '"%s" (access mode %s)%s',
            $calendar->getTitle(),
            OrganizerCalendarAccessModeService::modeOf($calendar)->value,
            $since === null ? '' : ', since '.$this->localTime($since),
        ));

        $consultations = $this->consultationRepository->findLatestForCalendar($calendar->getId(), $limit, $since);

        if ($consultations->isEmpty()) {
            $this->line('No consultation yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Date (Mayotte)', 'Prénom', 'Appareil', 'IP', 'Navigateur', 'Type'],
            $consultations->map(fn (OrganizerCalendarConsultationDomainObject $consultation): array => [
                $this->localTime($consultation->getCreatedAt()),
                $consultation->getVisitorName() ?? '(code)',
                $consultation->getDeviceId() === null ? '' : substr($consultation->getDeviceId(), 0, 4),
                (string) $consultation->getIpAddress(),
                UserAgentSummarizer::summarize($consultation->getUserAgent()),
                $consultation->getKind(),
            ])->all(),
        );

        $this->line('');
        $this->info('Total par prénom');
        $this->table(
            ['Prénom', 'Ouvertures', 'Visites', 'Appareils', 'Dernière fois (Mayotte)'],
            $this->consultationRepository->totalsByVisitor($calendar->getId(), $since)
                ->map(fn (OrganizerCalendarVisitorTotalDTO $total): array => [
                    $total->visitorName ?? '(code)',
                    $total->openings,
                    $total->visits,
                    $total->devices,
                    $this->localTime($total->lastSeenAt),
                ])->all(),
        );

        return self::SUCCESS;
    }

    private function localTime(?string $utc): string
    {
        return $utc === null ? '' : Carbon::parse($utc, 'UTC')->setTimezone(self::TIMEZONE)->format('d/m/Y H:i:s');
    }
}
