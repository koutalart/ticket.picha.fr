<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\DailyReport;

use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignStatus;
use HiEvents\Repository\Interfaces\EventDailyReportRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventDailyActivityDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsDefaultRemindersService;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The SMS that follows the daily report: what happened in the exchanges of the local day (SMS and live chat), the
 * refund requests in progress and the scheduled reminders to come. Topics are found with keywords, so the text never
 * depends on a model and never quotes what people wrote.
 */
class EventDailyActivityService
{
    public const TOPIC_REGISTRATION = 'registration';

    public const TOPIC_REFUND = 'refund';

    public const TOPIC_TSHIRT_PICKUP = 'tshirt_pickup';

    public const TOPIC_TSHIRT = 'tshirt';

    public const TOPIC_PICKUP = 'pickup';

    public const TOPIC_PRACTICAL = 'practical';

    public const TOPIC_OTHER = 'other';

    private const MAX_NEXT_STEPS = 3;

    private const KEYWORDS = [
        self::TOPIC_REFUND => ['rembours', 'annul', 'refund'],
        'tshirt' => ['tee-shirt', 'tee shirt', 'teeshirt', 't-shirt', 't shirt', 'tshirt', 'maillot'],
        'pickup' => ['retrait', 'retirer', 'recuper', 'dossard', 'chercher'],
        self::TOPIC_REGISTRATION => ['inscri', 'billet', 'ticket', 'reserv', 'payer', 'paye', 'paiement', 'commande', 'formulaire', 'lien'],
        self::TOPIC_PRACTICAL => ['heure', 'horaire', 'lieu', 'adresse', 'depart', 'parking', 'rendez', 'programme', 'parcours', 'arrivee'],
    ];

    public function __construct(
        private readonly EventDailyReportRepositoryInterface $reportRepository,
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly PlatformSupervisorService $supervisorService,
    ) {}

    public function build(int $eventId, int $organizerId, Carbon $local): EventDailyActivityDTO
    {
        $excludedPhones = array_values(array_unique(array_merge(
            $this->teamContactService->list($organizerId)
                ->map(fn (OrganizerTeamContactDomainObject $contact) => $contact->getPhone())
                ->all(),
            $this->supervisorService->phones(),
        )));

        $stats = $this->reportRepository->findActivity(
            $eventId,
            $organizerId,
            $local->copy()->startOfDay()->utc()->toDateTimeString(),
            $local->copy()->addDay()->startOfDay()->utc()->toDateTimeString(),
            $excludedPhones,
        );

        $topics = [];
        foreach ($stats->exchanges as $exchange) {
            $topic = self::topic($exchange);
            $topics[$topic] = ($topics[$topic] ?? 0) + 1;
        }

        $nextSteps = $this->nextSteps($eventId, $local);

        $lines = [self::summaryLine($stats->confirmationsSent, $topics, $stats->pendingRefunds)];
        if ($nextSteps !== []) {
            $lines[] = __('Next steps: :steps.', ['steps' => implode(', ', $nextSteps)], 'fr');
        }

        return new EventDailyActivityDTO(
            eventId: $eventId,
            stats: $stats,
            topics: $topics,
            nextSteps: $nextSteps,
            text: implode("\n", $lines)."\n\n".EventDailyReportService::SIGNATURE,
        );
    }

    public static function topic(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $matches = fn (string $group) => Str::contains($text, self::KEYWORDS[$group]);

        return match (true) {
            $matches(self::TOPIC_REFUND) => self::TOPIC_REFUND,
            $matches('tshirt') && $matches('pickup') => self::TOPIC_TSHIRT_PICKUP,
            $matches('tshirt') => self::TOPIC_TSHIRT,
            $matches('pickup') => self::TOPIC_PICKUP,
            $matches(self::TOPIC_REGISTRATION) => self::TOPIC_REGISTRATION,
            $matches(self::TOPIC_PRACTICAL) => self::TOPIC_PRACTICAL,
            default => self::TOPIC_OTHER,
        };
    }

    /**
     * @param  array<string, int>  $topics
     */
    public static function summaryLine(int $confirmationsSent, array $topics, int $pendingRefunds): string
    {
        $refunds = match (true) {
            $pendingRefunds === 0 => __('No refund request in progress.', [], 'fr'),
            $pendingRefunds === 1 => __('1 refund request in progress.', [], 'fr'),
            default => __(':count refund requests in progress.', ['count' => $pendingRefunds], 'fr'),
        };

        $people = array_sum($topics);
        if ($confirmationsSent === 0 && $people === 0) {
            $line = __('No question received today.', [], 'fr');

            return $pendingRefunds > 0 ? $line.' '.$refunds : $line;
        }

        $items = [];
        if ($confirmationsSent > 0) {
            $items[] = $confirmationsSent === 1
                ? __('1 registration confirmation sent', [], 'fr')
                : __(':count registration confirmations sent', ['count' => $confirmationsSent], 'fr');
        }

        if ($people === 0) {
            $items[] = __('no question received', [], 'fr');
        }

        $ordered = $topics;
        unset($ordered[self::TOPIC_REGISTRATION], $ordered[self::TOPIC_OTHER]);
        arsort($ordered);
        if (isset($topics[self::TOPIC_REGISTRATION])) {
            $ordered = [self::TOPIC_REGISTRATION => $topics[self::TOPIC_REGISTRATION]] + $ordered;
        }
        if (isset($topics[self::TOPIC_OTHER])) {
            $ordered[self::TOPIC_OTHER] = $topics[self::TOPIC_OTHER];
        }

        foreach ($ordered as $topic => $count) {
            $items[] = self::topicLabel($topic, $count, count($ordered) === 1);
        }

        return __('On the exchanges side today: :items.', ['items' => implode(', ', $items)], 'fr').' '.$refunds;
    }

    public static function topicLabel(string $topic, int $count, bool $onlyTopic = false): string
    {
        $one = $count === 1;
        $replace = ['count' => $count];

        return match ($topic) {
            self::TOPIC_REGISTRATION => $one
                ? __('1 person helped to register', [], 'fr')
                : __(':count people helped to register', $replace, 'fr'),
            self::TOPIC_REFUND => $one
                ? __('1 question about a refund or a cancellation', [], 'fr')
                : __(':count questions about a refund or a cancellation', $replace, 'fr'),
            self::TOPIC_TSHIRT_PICKUP => $one
                ? __('1 question about the T-shirt pickup', [], 'fr')
                : __(':count questions about the T-shirt pickup', $replace, 'fr'),
            self::TOPIC_TSHIRT => $one
                ? __('1 question about the T-shirt', [], 'fr')
                : __(':count questions about the T-shirt', $replace, 'fr'),
            self::TOPIC_PICKUP => $one
                ? __('1 question about a pickup', [], 'fr')
                : __(':count questions about a pickup', $replace, 'fr'),
            self::TOPIC_PRACTICAL => $one
                ? __('1 question about the place or the times', [], 'fr')
                : __(':count questions about the place or the times', $replace, 'fr'),
            default => match (true) {
                $onlyTopic && $one => __('1 question handled', [], 'fr'),
                $onlyTopic => __(':count questions handled', $replace, 'fr'),
                $one => __('1 other question', [], 'fr'),
                default => __(':count other questions', $replace, 'fr'),
            },
        };
    }

    /**
     * The SMS campaigns of the event still scheduled, soonest first, in the local time of the report.
     *
     * @return array<int, string>
     */
    public function nextSteps(int $eventId, Carbon $local): array
    {
        $nowUtc = $local->copy()->utc()->toDateTimeString();

        return $this->campaignRepository->findWhere([
            SmsCampaignDomainObjectAbstract::EVENT_ID => $eventId,
            SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
            [SmsCampaignDomainObjectAbstract::SCHEDULED_AT, '>', $nowUtc],
        ])
            ->sortBy(fn (SmsCampaignDomainObject $campaign) => [$campaign->getScheduledAt(), $campaign->getId()])
            ->take(self::MAX_NEXT_STEPS)
            ->map(fn (SmsCampaignDomainObject $campaign) => __(':label on :date at :time', [
                'label' => self::stepLabel($campaign),
                'date' => Carbon::parse($campaign->getScheduledAt(), 'UTC')->setTimezone($local->getTimezone())->format('d/m'),
                'time' => Carbon::parse($campaign->getScheduledAt(), 'UTC')->setTimezone($local->getTimezone())->format('H\hi'),
            ], 'fr'))
            ->values()
            ->all();
    }

    public static function stepLabel(SmsCampaignDomainObject $campaign): string
    {
        $key = (string) $campaign->getAutoKey();
        if ($key === SmsDefaultRemindersService::KEY_SAME_DAY) {
            return __('reminder on the day', [], 'fr');
        }

        if (preg_match('/^J-(\d+)$/', $key, $matches) === 1
            || preg_match('/\b[JD]\s*-\s*(\d+)\b/u', (string) $campaign->getName(), $matches) === 1) {
            return __('reminder D-:days', ['days' => $matches[1]], 'fr');
        }

        $name = SmsAssistantReplyGenerator::cleanText((string) $campaign->getName());

        return $name !== '' ? $name : __('SMS campaign', [], 'fr');
    }
}
