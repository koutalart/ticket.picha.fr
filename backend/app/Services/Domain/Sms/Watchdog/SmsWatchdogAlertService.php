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
 * At most one e-mail per run, listing every new anomaly, and no more than one e-mail per cooldown: anomalies found
 * meanwhile wait for the next e-mail. Writes nothing but its own bookkeeping, and never sends an SMS to a participant.
 */
class SmsWatchdogAlertService
{
    public const DEFAULT_COOLDOWN_MINUTES = 5;

    public function __construct(
        private readonly SmsWatchdogAlertRepositoryInterface $alertRepository,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(SmsWatchdogDetectionDTO $detection): SmsWatchdogRunResultDTO
    {
        $now = Carbon::now('UTC');
        $nowString = $now->toDateTimeString();
        $detected = count($detection->anomalies);

        $toAlert = $this->record($detection->anomalies, $nowString);
        $resolved = $this->resolveGone($detection, $nowString);

        if ($toAlert === []) {
            return new SmsWatchdogRunResultDTO($detected, 0, $resolved);
        }

        $lastAlertedAt = $this->alertRepository->findLastAlertedAt();
        if ($lastAlertedAt !== null && Carbon::parse($lastAlertedAt, 'UTC')->greaterThan($now->copy()->subMinutes($this->cooldownMinutes()))) {
            return new SmsWatchdogRunResultDTO($detected, 0, $resolved, deferred: count($toAlert));
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            $this->logger->warning('SMS watchdog: anomalies found but no alert e-mail is configured', ['count' => count($toAlert)]);

            return new SmsWatchdogRunResultDTO($detected, 0, $resolved, recipientMissing: true);
        }

        $this->mailer
            ->to($recipients)
            ->locale(CustomerLocale::get())
            ->send(new SmsWatchdogAlertMail(array_values($toAlert)));

        $this->alertRepository->updateWhere(
            [SmsWatchdogAlertDomainObjectAbstract::ALERTED_AT => $nowString],
            [[SmsWatchdogAlertDomainObjectAbstract::ID, 'in', array_keys($toAlert)]],
        );

        $this->notifyBySms(count($toAlert));

        return new SmsWatchdogRunResultDTO($detected, count($toAlert), $resolved);
    }

    /**
     * @return array<int, string>
     */
    public function recipients(): array
    {
        foreach (['services.sms_watchdog.alert_email', 'app.platform_support_email'] as $key) {
            $emails = array_values(array_unique(array_filter(
                array_map('trim', explode(',', (string) $this->config->get($key))),
                fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                    && ! str_ends_with(strtolower($email), '@example.com'),
            )));

            if ($emails !== []) {
                return $emails;
            }
        }

        return [];
    }

    /**
     * Records the anomalies in a few queries and returns the ones not alerted yet, by alert id.
     *
     * @param  array<int, SmsWatchdogAnomalyDTO>  $anomalies
     * @return array<int, SmsWatchdogAnomalyDTO>
     */
    private function record(array $anomalies, string $now): array
    {
        if ($anomalies === []) {
            return [];
        }

        $existing = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn (SmsWatchdogAnomalyDTO $anomaly) => $anomaly->key, $anomalies))), 500) as $keys) {
            foreach ($this->alertRepository->findWhereIn(SmsWatchdogAlertDomainObjectAbstract::ANOMALY_KEY, $keys) as $alert) {
                /** @var SmsWatchdogAlertDomainObject $alert */
                $existing[$alert->getAnomalyType().'|'.$alert->getAnomalyKey()] = $alert;
            }
        }

        $toAlert = [];
        $seenIds = [];
        foreach ($anomalies as $anomaly) {
            $alert = $existing[$anomaly->type->name.'|'.$anomaly->key] ?? null;

            if ($alert === null) {
                $alert = $this->create($anomaly, $now);
            } elseif ($alert->getResolvedAt() !== null) {
                $alert = $this->alertRepository->updateFromArray($alert->getId(), [
                    SmsWatchdogAlertDomainObjectAbstract::FIRST_SEEN_AT => $now,
                    SmsWatchdogAlertDomainObjectAbstract::LAST_SEEN_AT => $now,
                    SmsWatchdogAlertDomainObjectAbstract::ALERTED_AT => null,
                    SmsWatchdogAlertDomainObjectAbstract::RESOLVED_AT => null,
                ]);
            } else {
                $seenIds[] = $alert->getId();
            }

            if ($alert->getAlertedAt() === null) {
                $toAlert[$alert->getId()] = $anomaly;
            }
        }

        foreach (array_chunk($seenIds, 500) as $ids) {
            $this->alertRepository->updateWhere(
                [SmsWatchdogAlertDomainObjectAbstract::LAST_SEEN_AT => $now],
                [[SmsWatchdogAlertDomainObjectAbstract::ID, 'in', $ids]],
            );
        }

        return $toAlert;
    }

    private function create(SmsWatchdogAnomalyDTO $anomaly, string $now): SmsWatchdogAlertDomainObject
    {
        $where = [
            SmsWatchdogAlertDomainObjectAbstract::ANOMALY_TYPE => $anomaly->type->name,
            SmsWatchdogAlertDomainObjectAbstract::ANOMALY_KEY => $anomaly->key,
        ];

        try {
            return $this->alertRepository->create([
                ...$where,
                SmsWatchdogAlertDomainObjectAbstract::ORGANIZER_ID => $anomaly->organizerId,
                SmsWatchdogAlertDomainObjectAbstract::FIRST_SEEN_AT => $now,
                SmsWatchdogAlertDomainObjectAbstract::LAST_SEEN_AT => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->alertRepository->findFirstWhere($where);
        }
    }

    /**
     * Open alerts no longer detected are resolved, only for checks that ran to the end.
     */
    private function resolveGone(SmsWatchdogDetectionDTO $detection, string $now): int
    {
        $stillThere = [];
        foreach ($detection->anomalies as $anomaly) {
            $stillThere[$anomaly->type->name][] = $anomaly->key;
        }

        $organizersByType = [];
        foreach ($detection->completeChecks as $organizerId => $types) {
            foreach ($types as $type) {
                $organizersByType[$type][] = $organizerId;
            }
        }

        $resolved = 0;
        foreach ($organizersByType as $type => $organizerIds) {
            $resolved += $this->alertRepository->updateWhere(
                [SmsWatchdogAlertDomainObjectAbstract::RESOLVED_AT => $now],
                [
                    SmsWatchdogAlertDomainObjectAbstract::ANOMALY_TYPE => $type,
                    [SmsWatchdogAlertDomainObjectAbstract::ORGANIZER_ID, 'in', $organizerIds],
                    [SmsWatchdogAlertDomainObjectAbstract::RESOLVED_AT, 'null', null],
                    [SmsWatchdogAlertDomainObjectAbstract::ANOMALY_KEY, 'not in', $stillThere[$type] ?? []],
                ],
            );
        }

        return $resolved;
    }

    private function cooldownMinutes(): int
    {
        $value = $this->config->get('services.sms_watchdog.alert_cooldown_minutes');

        return max(0, $value === null || $value === '' ? self::DEFAULT_COOLDOWN_MINUTES : (int) $value);
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
