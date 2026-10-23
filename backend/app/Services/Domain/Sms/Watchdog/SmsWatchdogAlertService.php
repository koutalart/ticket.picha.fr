<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Watchdog;

use HiEvents\DomainObjects\Generated\SmsWatchdogAlertDomainObjectAbstract;
use HiEvents\DomainObjects\SmsWatchdogAlertDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Mail\Sms\SmsWatchdogAlertMail;
use HiEvents\Repository\Interfaces\SmsWatchdogAlertRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogAnomalyDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogDetectionDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogRunResultDTO;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Keeps one alert per anomaly: the platform owner is told once, and again only if the anomaly goes away and comes back.
 * Writes nothing but its own bookkeeping, and never sends an SMS to a participant.
 */
class SmsWatchdogAlertService
{
    public function __construct(
        private readonly SmsWatchdogAlertRepositoryInterface $alertRepository,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(SmsWatchdogDetectionDTO $detection): SmsWatchdogRunResultDTO
    {
        $now = Carbon::now('UTC')->toDateTimeString();

        $toAlert = [];
        foreach ($detection->anomalies as $anomaly) {
            $alert = $this->record($anomaly, $now);
            if ($alert->getAlertedAt() === null) {
                $toAlert[$alert->getId()] = $anomaly;
            }
        }

        $resolved = $this->resolveGone($detection, $now);

        if ($toAlert === []) {
            return new SmsWatchdogRunResultDTO(count($detection->anomalies), 0, $resolved);
        }

        $recipient = $this->recipient();
        if ($recipient === null) {
            $this->logger->warning('SMS watchdog: anomalies found but no alert e-mail is configured', ['count' => count($toAlert)]);

            return new SmsWatchdogRunResultDTO(count($detection->anomalies), 0, $resolved, recipientMissing: true);
        }

        $this->mailer
            ->to($recipient)
            ->locale(CustomerLocale::get())
            ->send(new SmsWatchdogAlertMail(array_values($toAlert)));

        $this->alertRepository->updateWhere(
            [SmsWatchdogAlertDomainObjectAbstract::ALERTED_AT => $now],
            [[SmsWatchdogAlertDomainObjectAbstract::ID, 'in', array_keys($toAlert)]],
        );

        $this->notifyBySms(count($toAlert));

        return new SmsWatchdogRunResultDTO(count($detection->anomalies), count($toAlert), $resolved);
    }

    private function record(SmsWatchdogAnomalyDTO $anomaly, string $now): SmsWatchdogAlertDomainObject
    {
        $where = [
            SmsWatchdogAlertDomainObjectAbstract::ANOMALY_TYPE => $anomaly->type->name,
            SmsWatchdogAlertDomainObjectAbstract::ANOMALY_KEY => $anomaly->key,
        ];

        /** @var SmsWatchdogAlertDomainObject|null $existing */
        $existing = $this->alertRepository->findFirstWhere($where);

        if ($existing === null) {
            try {
                return $this->alertRepository->create([
                    ...$where,
                    SmsWatchdogAlertDomainObjectAbstract::ORGANIZER_ID => $anomaly->organizerId,
                    SmsWatchdogAlertDomainObjectAbstract::FIRST_SEEN_AT => $now,
                    SmsWatchdogAlertDomainObjectAbstract::LAST_SEEN_AT => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = $this->alertRepository->findFirstWhere($where);
            }
        }

        if ($existing->getResolvedAt() !== null) {
            return $this->alertRepository->updateFromArray($existing->getId(), [
                SmsWatchdogAlertDomainObjectAbstract::FIRST_SEEN_AT => $now,
                SmsWatchdogAlertDomainObjectAbstract::LAST_SEEN_AT => $now,
                SmsWatchdogAlertDomainObjectAbstract::ALERTED_AT => null,
                SmsWatchdogAlertDomainObjectAbstract::RESOLVED_AT => null,
            ]);
        }

        return $this->alertRepository->updateFromArray($existing->getId(), [
            SmsWatchdogAlertDomainObjectAbstract::LAST_SEEN_AT => $now,
        ]);
    }

    /**
     * Open alerts no longer detected are resolved, only for checks that ran to the end.
     */
    private function resolveGone(SmsWatchdogDetectionDTO $detection, string $now): int
    {
        $resolved = 0;

        foreach ($detection->completeChecks as $organizerId => $types) {
            foreach ($types as $type) {
                $stillThere = [];
                foreach ($detection->anomalies as $anomaly) {
                    if ($anomaly->organizerId === $organizerId && $anomaly->type->name === $type) {
                        $stillThere[] = $anomaly->key;
                    }
                }

                $resolved += $this->alertRepository->updateWhere(
                    [SmsWatchdogAlertDomainObjectAbstract::RESOLVED_AT => $now],
                    [
                        SmsWatchdogAlertDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                        SmsWatchdogAlertDomainObjectAbstract::ANOMALY_TYPE => $type,
                        [SmsWatchdogAlertDomainObjectAbstract::RESOLVED_AT, 'null', null],
                        [SmsWatchdogAlertDomainObjectAbstract::ANOMALY_KEY, 'not in', $stillThere],
                    ],
                );
            }
        }

        return $resolved;
    }

    private function recipient(): ?string
    {
        foreach (['services.sms_watchdog.alert_email', 'app.platform_support_email'] as $key) {
            $email = trim((string) $this->config->get($key));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false && ! str_ends_with(strtolower($email), '@example.com')) {
                return $email;
            }
        }

        return null;
    }

    /**
     * TODO: send the alert by SMS once the platform has a line of its own. Every line today belongs to an organizer,
     * and an SMS sent through it would land in that organizer's conversations, so nothing is sent for now.
     */
    private function notifyBySms(int $count): void
    {
        $phone = trim((string) $this->config->get('services.sms_watchdog.alert_phone'));
        if ($phone === '') {
            return;
        }

        $this->logger->warning('SMS watchdog: SMS alert requested but no platform SMS line exists, e-mail only', ['count' => $count]);
    }
}
