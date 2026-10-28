<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\Services\Domain\Sms\Team\PlatformSupervisionAlertService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the platform supervision that campaign SMS could not be handed to the central SMS queue, at most once per
 * campaign within the cooldown, so a queue down for hours does not send an alert at every run.
 */
class SmsFileAlertService
{
    public function __construct(
        private readonly PlatformSupervisionAlertService $supervisionAlertService,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function campaignNotQueued(int $organizerId, string $campaign, int $count, string $error, bool $retried): void
    {
        $this->logger->error('Campaign SMS not handed to the SMS queue', [
            'organizer_id' => $organizerId,
            'campaign' => $campaign,
            'count' => $count,
            'retried' => $retried,
            'error' => $error,
        ]);

        $cooldown = max(1, (int) $this->config->get('services.sms_file.alert_cooldown_minutes', 60));
        if (! $this->cache->add('sms-file-alert:'.$organizerId.':'.$campaign, true, now()->addMinutes($cooldown))) {
            return;
        }

        $sms = $retried
            ? __('PICHA alert: the SMS queue cannot be reached. :count SMS of :campaign (organizer :organizer) wait and will be retried automatically. Error: :error', [
                'count' => $count, 'campaign' => $campaign, 'organizer' => $organizerId, 'error' => mb_substr($error, 0, 120),
            ], 'fr')
            : __('PICHA alert: the SMS queue refused :count SMS of :campaign (organizer :organizer), marked failed. Error: :error', [
                'count' => $count, 'campaign' => $campaign, 'organizer' => $organizerId, 'error' => mb_substr($error, 0, 120),
            ], 'fr');

        try {
            $this->supervisionAlertService->alert(
                organizerId: $organizerId,
                sms: $sms,
                subject: __('Campaign SMS not handed to the SMS queue'),
                intro: $retried
                    ? __('The central SMS queue cannot be reached. Nothing was sent straight to the phone, the SMS will be retried at the next run.')
                    : __('The central SMS queue refused these SMS. They are marked failed.'),
                details: [
                    __('Campaign') => $campaign,
                    __('Organizer') => '#'.$organizerId,
                    __('SMS') => (string) $count,
                    __('Error') => $error,
                ],
                url: null,
            );
        } catch (Throwable $exception) {
            $this->logger->error('Supervision alert about the SMS queue could not be sent', [
                'organizer_id' => $organizerId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
