<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\DailyReport;

use HiEvents\DomainObjects\EventDailyReportDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDailyReportDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Exceptions\EventDailyReportException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Repository\Interfaces\EventDailyReportRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventDailyActivityDTO;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventDailyReportDTO;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventDailyReportSendResultDTO;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisorService;
use HiEvents\Services\Domain\Sms\Team\SmsDirectSendService;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * The daily SMS report of an event to the organizer team (ORGANIZER and COPY contacts), and in option to the platform
 * supervisors. It leaves once per local day, as soon as the local time reaches the chosen time. When the activity
 * option is on, the SMS on the exchanges of the day follows it, to the same people.
 */
class EventDailyReportService
{
    public const DEFAULT_TIMEZONE = 'Indian/Mayotte';

    public const DEFAULT_SEND_TIME = '20:00';

    public const SIGNATURE = 'Anicka';

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly EventDailyReportRepositoryInterface $reportRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly PlatformSupervisorService $supervisorService,
        private readonly SmsDirectSendService $directSendService,
        private readonly EventDailyActivityService $activityService,
        private readonly CacheManager $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function find(int $eventId): ?EventDailyReportDomainObject
    {
        return $this->reportRepository->findFirstWhere([EventDailyReportDomainObjectAbstract::EVENT_ID => $eventId]);
    }

    /**
     * @throws EventDailyReportException
     */
    public function configure(
        int $eventId,
        ?bool $enabled = null,
        ?string $sendTime = null,
        ?string $timezone = null,
        ?bool $copySupervisors = null,
        ?bool $activityEnabled = null,
    ): EventDailyReportDomainObject {
        $attributes = [];

        if ($enabled !== null) {
            $attributes[EventDailyReportDomainObjectAbstract::ENABLED] = $enabled;
        }

        if ($sendTime !== null) {
            $attributes[EventDailyReportDomainObjectAbstract::SEND_TIME] = self::normalizeTime($sendTime);
        }

        if ($timezone !== null) {
            if (! in_array($timezone, timezone_identifiers_list(), true)) {
                throw new EventDailyReportException(__('Unknown time zone'));
            }
            $attributes[EventDailyReportDomainObjectAbstract::TIMEZONE] = $timezone;
        }

        if ($copySupervisors !== null) {
            $attributes[EventDailyReportDomainObjectAbstract::COPY_SUPERVISORS] = $copySupervisors;
        }

        if ($activityEnabled !== null) {
            $attributes[EventDailyReportDomainObjectAbstract::ACTIVITY_ENABLED] = $activityEnabled;
        }

        $existing = $this->find($eventId);
        if ($existing !== null) {
            return $attributes === [] ? $existing : $this->reportRepository->updateFromArray($existing->getId(), $attributes);
        }

        return $this->reportRepository->create([
            EventDailyReportDomainObjectAbstract::EVENT_ID => $eventId,
            EventDailyReportDomainObjectAbstract::ENABLED => false,
            EventDailyReportDomainObjectAbstract::SEND_TIME => self::DEFAULT_SEND_TIME,
            EventDailyReportDomainObjectAbstract::TIMEZONE => self::DEFAULT_TIMEZONE,
            ...$attributes,
        ]);
    }

    /**
     * @throws EventDailyReportException
     */
    public static function normalizeTime(string $time): string
    {
        if (preg_match('/^\s*([01]?\d|2[0-3])\s*[:hH]\s*([0-5]\d)\s*$/', $time, $matches) !== 1) {
            throw new EventDailyReportException(__('The time must be in the HH:MM format'));
        }

        return sprintf('%02d:%s', (int) $matches[1], $matches[2]);
    }

    /**
     * @throws EventDailyReportException
     */
    public function build(int $eventId, ?Carbon $now = null): EventDailyReportDTO
    {
        $event = $this->findEvent($eventId);
        $timezone = $this->find($eventId)?->getTimezone() ?: self::DEFAULT_TIMEZONE;
        $local = ($now ?? Carbon::now('UTC'))->copy()->setTimezone($timezone);

        $stats = $this->reportRepository->findRegistrationStats(
            $eventId,
            $local->copy()->startOfDay()->utc()->toDateTimeString(),
            $local->copy()->addDay()->startOfDay()->utc()->toDateTimeString(),
            $local->copy()->utc()->toDateTimeString(),
        );

        $text = implode("\n", [
            __(':event, update of :date at :time', [
                'event' => trim($event->getTitle()),
                'date' => $local->format('d/m'),
                'time' => $local->format('H\hi'),
            ], 'fr'),
            __('Registrations: :count (+:new today)', [
                'count' => $stats->totalRegistrations,
                'new' => $stats->todayRegistrations,
            ], 'fr'),
            __('Registration revenue: :amount', ['amount' => self::formatAmount($stats->revenue, $event->getCurrency())], 'fr'),
            __('Open carts: :count', ['count' => $stats->openCarts], 'fr'),
            self::SIGNATURE,
        ]);

        return new EventDailyReportDTO(
            eventId: $eventId,
            organizerId: (int) $event->getOrganizerId(),
            eventTitle: $event->getTitle(),
            currency: $event->getCurrency(),
            localDateTime: $local->toDateTimeString(),
            timezone: $timezone,
            stats: $stats,
            text: $text,
        );
    }

    /**
     * @throws EventDailyReportException
     */
    public function buildActivity(int $eventId, ?Carbon $now = null): EventDailyActivityDTO
    {
        $event = $this->findEvent($eventId);
        $timezone = $this->find($eventId)?->getTimezone() ?: self::DEFAULT_TIMEZONE;
        $local = ($now ?? Carbon::now('UTC'))->copy()->setTimezone($timezone);

        return $this->activityService->build($eventId, (int) $event->getOrganizerId(), $local);
    }

    public static function formatAmount(float $amount, string $currency): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
        $symbol = strtoupper($currency) === 'EUR' ? '€' : strtoupper($currency);

        return number_format($amount, $decimals, ',', ' ').' '.$symbol;
    }

    /**
     * Sends the report now, whatever its schedule, without counting it as the report of the day.
     *
     * @throws EventDailyReportException
     * @throws SmsGatewayNotConfiguredException
     */
    public function sendNow(int $eventId, ?Carbon $now = null): EventDailyReportSendResultDTO
    {
        $report = $this->build($eventId, $now);
        $settings = $this->find($eventId);
        $activity = $settings?->getActivityEnabled() ? $this->buildActivity($eventId, $now) : null;

        return $this->deliver($report, (bool) $settings?->getCopySupervisors(), $activity);
    }

    /**
     * Sends every enabled report whose local time is reached and that did not leave yet this local day.
     *
     * @return array<int, EventDailyReportSendResultDTO>
     */
    public function sendDue(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $results = [];

        /** @var Collection<int, EventDailyReportDomainObject> $reports */
        $reports = $this->reportRepository->findWhere([EventDailyReportDomainObjectAbstract::ENABLED => true]);

        foreach ($reports as $report) {
            if (! self::isDue($report, $now)) {
                continue;
            }

            $lock = $this->cache->store()->lock('event-daily-report:'.$report->getId(), self::LOCK_SECONDS);
            if (! $lock->get()) {
                continue;
            }

            try {
                $localDate = $now->copy()->setTimezone($report->getTimezone() ?: self::DEFAULT_TIMEZONE)->toDateString();
                if (! $this->reportRepository->claimDay($report->getId(), $localDate)) {
                    continue;
                }

                $results[] = $this->deliver(
                    $this->build($report->getEventId(), $now),
                    $report->getCopySupervisors(),
                    $report->getActivityEnabled() ? $this->buildActivity($report->getEventId(), $now) : null,
                );
            } catch (EventDailyReportException|SmsGatewayNotConfiguredException $exception) {
                $this->logger->warning('Daily event report not sent', [
                    'event_id' => $report->getEventId(),
                    'error' => $exception->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }

        return $results;
    }

    public static function isDue(EventDailyReportDomainObject $report, Carbon $now): bool
    {
        if (! $report->getEnabled()) {
            return false;
        }

        $local = $now->copy()->setTimezone($report->getTimezone() ?: self::DEFAULT_TIMEZONE);
        if ($local->format('H:i') < $report->getSendTime()) {
            return false;
        }

        $lastSentOn = $report->getLastSentOn();

        return $lastSentOn === null || substr($lastSentOn, 0, 10) < $local->toDateString();
    }

    /**
     * @throws SmsGatewayNotConfiguredException
     */
    private function deliver(EventDailyReportDTO $report, bool $copySupervisors, ?EventDailyActivityDTO $activity = null): EventDailyReportSendResultDTO
    {
        $phones = $this->teamContactService->list($report->organizerId)
            ->map(fn (OrganizerTeamContactDomainObject $contact) => $contact->getPhone())
            ->values()
            ->all();

        $team = $this->directSendService->sendToMany($report->organizerId, $phones, $report->text);

        $supervision = null;
        $supervisors = array_values(array_diff($this->supervisorService->phones(), $phones));
        if ($copySupervisors && $supervisors !== []) {
            $supervision = $this->directSendService->sendToMany($report->organizerId, $supervisors, $report->text, isSupervision: true);
        }

        if ($activity !== null) {
            $this->directSendService->sendToMany($report->organizerId, $phones, $activity->text);
            if ($copySupervisors && $supervisors !== []) {
                $this->directSendService->sendToMany($report->organizerId, $supervisors, $activity->text, isSupervision: true);
            }
        }

        $this->logger->info('Daily event report sent', [
            'event_id' => $report->eventId,
            'with_activity' => $activity !== null,
            'sent' => count($team->sent) + count($supervision?->sent ?? []),
            'opted_out' => count($team->optedOut) + count($supervision?->optedOut ?? []),
            'failed' => count($team->failed) + count($supervision?->failed ?? []),
        ]);

        return new EventDailyReportSendResultDTO($report->eventId, $report, $team, $supervision, $activity);
    }

    /**
     * @throws EventDailyReportException
     */
    private function findEvent(int $eventId): EventDomainObject
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $eventId]);
        if ($event === null) {
            throw new EventDailyReportException(__('Event not found'));
        }

        return $event;
    }
}
