<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Services\Application\Handlers\Sms\DTO\SmsConnectionTestResultDTO;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;

class TestSmsConnectionHandler
{
    public function __construct(
        private readonly SmsSettingsService $settingsService,
        private readonly HttpSmsClient $httpSmsClient,
    ) {}

    /**
     * @throws SmsGatewayNotConfiguredException
     * @throws SmsGatewayException
     */
    public function handle(int $accountId, int $organizerId): SmsConnectionTestResultDTO
    {
        $settings = $this->settingsService->getOrCreate($accountId, $organizerId);
        $sharedLine = $this->settingsService->findSharedLine($settings);
        $line = $sharedLine ?? $settings;

        if (! $line->getApiKey()) {
            throw new SmsGatewayNotConfiguredException(__('Add your PICHA SMS API key first'));
        }

        $phoneNumbers = $this->httpSmsClient->listPhoneNumbers($line->getApiKey());
        $registered = $line->getFromNumber() !== null && in_array($line->getFromNumber(), $phoneNumbers, true);

        return new SmsConnectionTestResultDTO(
            phone_numbers: $sharedLine === null ? $phoneNumbers : ($registered ? [$line->getFromNumber()] : []),
            from_number_registered: $registered,
        );
    }
}
