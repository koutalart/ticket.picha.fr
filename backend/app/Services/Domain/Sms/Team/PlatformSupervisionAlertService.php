<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Team;

use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Mail\Sms\PlatformSupervisionAlertMail;
use HiEvents\Services\Domain\Sms\Watchdog\SmsWatchdogAlertService;
use Illuminate\Contracts\Mail\Mailer;
use Psr\Log\LoggerInterface;

/**
 * Tells the platform supervision at once: by SMS to PLATFORM_SUPERVISOR_PHONES, sent from the organizer's line as a
 * supervision copy (it never moves the attribution), and by e-mail to SMS_WATCHDOG_ALERT_EMAIL.
 */
class PlatformSupervisionAlertService
{
    public function __construct(
        private readonly PlatformSupervisorService $supervisorService,
        private readonly SmsDirectSendService $directSendService,
        private readonly SmsWatchdogAlertService $watchdogAlertService,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, string>  $details
     */
    public function alert(int $organizerId, string $sms, string $subject, string $intro, array $details, ?string $url): void
    {
        $phones = $this->supervisorService->phones();
        if ($phones !== []) {
            try {
                $this->directSendService->sendToMany($organizerId, $phones, $sms, isSupervision: true);
            } catch (SmsGatewayNotConfiguredException $exception) {
                $this->logger->warning('Supervision alert: no SMS line for this organizer, e-mail only', [
                    'organizer_id' => $organizerId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $recipients = $this->watchdogAlertService->recipients();
        if ($recipients === []) {
            $this->logger->warning('Supervision alert: no alert e-mail configured', ['organizer_id' => $organizerId]);

            return;
        }

        $this->mailer
            ->to($recipients)
            ->locale(CustomerLocale::get())
            ->send(new PlatformSupervisionAlertMail($subject, $intro, $details, $url));
    }

    public static function conversationUrl(int $organizerId, int $conversationId): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/manage/organizer/'.$organizerId.'/sms?conversation='.$conversationId;
    }
}
