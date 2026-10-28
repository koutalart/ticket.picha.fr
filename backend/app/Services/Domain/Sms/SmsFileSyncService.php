<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\SmsFileRejectedException;
use HiEvents\Exceptions\SmsFileUnavailableException;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Infrastructure\SmsFile\SmsFileClient;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Safety net next to the sms.picha.fr webhook: asks the queue about the SMS it holds that are not sent yet, links
 * them to their sms.picha.fr id and moves their status forward. Each SMS is asked about at most once per interval.
 */
class SmsFileSyncService
{
    private const LOOKBACK_DAYS = 3;

    public function __construct(
        private readonly SmsFileClient $fileClient,
        private readonly SmsFileStatusService $statusService,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return int the number of SMS checked
     */
    public function sync(): int
    {
        if (! $this->fileClient->isEnabled()) {
            return 0;
        }

        $now = Carbon::now('UTC');
        $messages = $this->messageRepository->findQueuedForSync(
            createdSince: $now->copy()->subDays(self::LOOKBACK_DAYS)->toDateTimeString(),
            checkedBefore: $now->copy()->subMinutes(max(1, (int) $this->config->get('services.sms_file.sync_interval_minutes', 5)))->toDateTimeString(),
            limit: max(1, (int) $this->config->get('services.sms_file.sync_limit', 100)),
        );

        $checked = 0;
        foreach ($messages as $message) {
            try {
                $result = $this->fileClient->find((int) $message->getQueueMessageId());
            } catch (SmsFileUnavailableException) {
                break;
            } catch (SmsFileRejectedException $exception) {
                $this->markChecked($message);
                $this->logger->warning('SMS unknown to the SMS queue', [
                    'sms_message_id' => $message->getId(),
                    'queue_message_id' => $message->getQueueMessageId(),
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $this->statusService->applyToMessage($message, $result);

            /** @var SmsCampaignRecipientDomainObject|null $recipient */
            $recipient = $this->recipientRepository->findFirstWhere([
                SmsCampaignRecipientDomainObjectAbstract::QUEUE_MESSAGE_ID => $result->id,
            ]);
            if ($recipient !== null) {
                $this->statusService->applyToRecipient($recipient, $result);
            }

            $checked++;
        }

        return $checked;
    }

    private function markChecked(SmsMessageDomainObject $message): void
    {
        $this->messageRepository->updateFromArray($message->getId(), [
            SmsMessageDomainObjectAbstract::QUEUE_CHECKED_AT => Carbon::now('UTC')->toDateTimeString(),
        ]);
    }
}
