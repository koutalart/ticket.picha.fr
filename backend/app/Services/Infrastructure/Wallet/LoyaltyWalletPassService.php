<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Wallet;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Exceptions\WalletNotConfiguredException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Support\Responsable;
use Psr\Log\LoggerInterface;
use Spatie\LaravelMobilePass\Builders\Apple\StoreCardPassBuilder;
use Spatie\LaravelMobilePass\Enums\BarcodeType;
use Spatie\LaravelMobilePass\Models\MobilePass;
use Throwable;

class LoyaltyWalletPassService
{
    public const BARCODE_PREFIX = 'PICHAFID:';

    private const DEFAULT_ICON_PATH = 'wallet/loyalty-icon.png';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAppleEnabled(): bool
    {
        return filled($this->config->get('mobile-pass.apple.type_identifier'))
            && filled($this->config->get('mobile-pass.apple.team_identifier'))
            && (filled($this->config->get('mobile-pass.apple.certificate'))
                || filled($this->config->get('mobile-pass.apple.certificate_path')));
    }

    public function isGoogleEnabled(): bool
    {
        return filled($this->config->get('mobile-pass.google.issuer_id'))
            && (filled($this->config->get('mobile-pass.google.service_account_key'))
                || filled($this->config->get('mobile-pass.google.service_account_key_path')));
    }

    public static function barcodeMessage(LoyaltyCardDomainObject $card): string
    {
        return self::BARCODE_PREFIX.$card->getPublicId();
    }

    public static function publicIdFromBarcode(string $scanned): ?string
    {
        $scanned = trim($scanned);

        if (str_starts_with($scanned, self::BARCODE_PREFIX)) {
            $scanned = substr($scanned, strlen(self::BARCODE_PREFIX));
        }

        return preg_match('/^[A-Za-z0-9]{16,40}$/', $scanned) === 1 ? $scanned : null;
    }

    public function buildApplePass(
        LoyaltyProgramDomainObject $program,
        LoyaltyCardDomainObject $card,
        string $organizerName,
        ?MobilePass $existing = null,
    ): StoreCardPassBuilder {
        $builder = new StoreCardPassBuilder([], [], $existing);

        $builder
            ->setSerialNumber('picha-fid-'.$card->getPublicId())
            ->setOrganizationName($organizerName)
            ->setDescription($program->getName())
            ->setLogoText($program->getName())
            ->setDownloadName('carte-fidelite')
            ->setBackgroundColor($program->getBackgroundColor())
            ->setForegroundColor($program->getForegroundColor())
            ->setLabelColor($program->getLabelColor())
            ->addField(
                key: 'stamps',
                value: $this->stampsLabel($program, $card),
                label: __('Stamps', [], 'fr'),
                changeMessage: __('Your loyalty card: :value', ['value' => '%@'], 'fr'),
            )
            ->addSecondaryField('member', $card->getFirstName(), label: __('Member', [], 'fr'))
            ->addSecondaryField('reward', $program->getRewardDescription(), label: __('Reward', [], 'fr'))
            ->addAuxiliaryField(
                key: 'rewards',
                value: (string) $card->getRewardsEarned(),
                label: __('Rewards earned', [], 'fr'),
                changeMessage: __('Congratulations, you unlocked: :reward', ['reward' => $program->getRewardDescription()], 'fr'),
            )
            ->addBackField('rules', __(':count stamps = :reward', [
                'count' => $program->getStampsRequired(),
                'reward' => $program->getRewardDescription(),
            ], 'fr'), label: __('How it works', [], 'fr'))
            ->setBarcode(BarcodeType::Qr, self::barcodeMessage($card), strtoupper(substr($card->getPublicId(), 0, 8)));

        $this->applyImages($builder, $program);

        foreach ($this->locations($program) as $location) {
            $builder->addLocation(
                latitude: $location['latitude'],
                longitude: $location['longitude'],
                relevantText: $location['relevant_text'],
            );
        }

        if ($program->getMaxDistance() !== null) {
            $builder->setMaxDistance($program->getMaxDistance());
        }

        if ($program->getBeaconUuid() !== null) {
            $builder->addBeacon(
                proximityUUID: strtoupper($program->getBeaconUuid()),
                major: $program->getBeaconMajor(),
                minor: $program->getBeaconMinor(),
                relevantText: $program->getBeaconRelevantText(),
            );
        }

        return $builder;
    }

    public function buildGoogleClass(LoyaltyProgramDomainObject $program, string $organizerName): LoyaltyGooglePassClass
    {
        $class = LoyaltyGooglePassClass::make($this->googleClassSuffix($program));

        $class
            ->setIssuerName($organizerName)
            ->setProgramName($program->getName(), 'fr')
            ->setProgramLogoUrl($this->logoUrl($program))
            ->setAccountNameLabel(__('Member', [], 'fr'), 'fr')
            ->setAccountIdLabel(__('Card', [], 'fr'), 'fr')
            ->setBackgroundColor($program->getBackgroundColor())
            ->addTextModule(__('How it works', [], 'fr'), __(':count stamps = :reward', [
                'count' => $program->getStampsRequired(),
                'reward' => $program->getRewardDescription(),
            ], 'fr'), 'rules');

        foreach ($this->locations($program) as $location) {
            $class->addMerchantLocation($location['latitude'], $location['longitude']);
        }

        return $class;
    }

    public function buildGoogleObject(
        LoyaltyProgramDomainObject $program,
        LoyaltyCardDomainObject $card,
        ?string $notification = null,
    ): LoyaltyGooglePassBuilder {
        $builder = LoyaltyGooglePassBuilder::make();

        $builder
            ->setClass($this->googleClassSuffix($program))
            ->setObjectSuffix('picha-fid-'.$card->getPublicId())
            ->setAccountId(strtoupper(substr($card->getPublicId(), 0, 8)))
            ->setAccountName($card->getFirstName())
            ->setBalanceString($this->stampsLabel($program, $card))
            ->setBalanceLabel(__('Stamps', [], 'fr'))
            ->setSecondaryBalanceString((string) $card->getRewardsEarned())
            ->setSecondaryBalanceLabel(__('Rewards earned', [], 'fr'))
            ->setBarcode(BarcodeType::Qr, self::barcodeMessage($card), strtoupper(substr($card->getPublicId(), 0, 8)));

        foreach ($this->locations($program) as $location) {
            $builder->addMerchantLocation($location['latitude'], $location['longitude']);
        }

        if ($notification !== null) {
            $builder->addTextModule(__('Latest news', [], 'fr'), $notification, 'latest');
        }

        return $builder;
    }

    /**
     * @throws WalletNotConfiguredException
     */
    public function createApplePass(LoyaltyProgramDomainObject $program, LoyaltyCardDomainObject $card, string $organizerName): string
    {
        if (! $this->isAppleEnabled()) {
            throw new WalletNotConfiguredException(__('Apple Wallet is not available yet.', [], 'fr'));
        }

        return (string) $this->buildApplePass($program, $card, $organizerName)->save()->getKey();
    }

    /**
     * @throws WalletNotConfiguredException
     */
    public function createGooglePass(LoyaltyProgramDomainObject $program, LoyaltyCardDomainObject $card, string $organizerName): string
    {
        if (! $this->isGoogleEnabled()) {
            throw new WalletNotConfiguredException(__('Google Wallet is not available yet.', [], 'fr'));
        }

        $this->buildGoogleClass($program, $organizerName)->save();

        return (string) $this->buildGoogleObject($program, $card)->save()->getKey();
    }

    public function syncGoogleClass(LoyaltyProgramDomainObject $program, string $organizerName): void
    {
        if (! $this->isGoogleEnabled()) {
            return;
        }

        try {
            $this->buildGoogleClass($program, $organizerName)->save();
        } catch (Throwable $exception) {
            $this->logger->error('Loyalty Google class sync failed', [
                'loyalty_program_id' => $program->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Rebuilds both passes from the current card state. Saving the MobilePass makes the
     * package push the change: APNs for Apple (the phone then fetches the new pass),
     * the Wallet REST API for Google.
     */
    public function syncCard(
        LoyaltyProgramDomainObject $program,
        LoyaltyCardDomainObject $card,
        string $organizerName,
        ?string $notification = null,
    ): void {
        $this->safely($card, function () use ($program, $card, $organizerName) {
            $existing = $this->findPass($card->getAppleMobilePassId());
            if ($existing !== null) {
                $this->buildApplePass($program, $card, $organizerName, $existing)->save();
            }
        });

        $this->safely($card, function () use ($program, $card, $notification) {
            $existing = $this->findPass($card->getGoogleMobilePassId());
            if ($existing === null) {
                return;
            }

            $payload = $this->buildGoogleObject($program, $card, $notification)->payload();
            if ($notification !== null) {
                $payload['messages'] = [[
                    'id' => 'stamp-'.$card->getTotalStamps(),
                    'header' => $program->getName(),
                    'body' => $notification,
                    'messageType' => 'TEXT_AND_NOTIFY',
                ]];
            }

            $content = $existing->content;
            $content['googleObjectPayload'] = $payload;
            $existing->update(['content' => $content]);
        });
    }

    public function applePass(LoyaltyCardDomainObject $card): ?Responsable
    {
        return $this->findPass($card->getAppleMobilePassId());
    }

    public function googleSaveUrl(LoyaltyCardDomainObject $card): ?string
    {
        return $this->findPass($card->getGoogleMobilePassId())?->addToWalletUrl();
    }

    /**
     * @return array<int, array{latitude: float, longitude: float, relevant_text: ?string}>
     */
    public function locations(LoyaltyProgramDomainObject $program): array
    {
        $raw = $program->getLocations();
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $locations = [];
        foreach (array_slice($raw, 0, 10) as $location) {
            if (! is_array($location) || ! is_numeric($location['latitude'] ?? null) || ! is_numeric($location['longitude'] ?? null)) {
                continue;
            }

            $text = trim((string) ($location['relevant_text'] ?? ''));
            $locations[] = [
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'relevant_text' => $text === '' ? null : $text,
            ];
        }

        return $locations;
    }

    private function stampsLabel(LoyaltyProgramDomainObject $program, LoyaltyCardDomainObject $card): string
    {
        return $card->getStamps().' / '.$program->getStampsRequired();
    }

    private function applyImages(StoreCardPassBuilder $builder, LoyaltyProgramDomainObject $program): void
    {
        if ($program->getLogoUrl() !== null) {
            $builder->setRemoteIconImage($program->getLogoUrl());
            $builder->setRemoteLogoImage($program->getLogoUrl());

            return;
        }

        $icon = resource_path(self::DEFAULT_ICON_PATH);
        $builder->setIconImage($icon);
        $builder->setLogoImage($icon);
    }

    private function logoUrl(LoyaltyProgramDomainObject $program): string
    {
        return $program->getLogoUrl()
            ?? rtrim((string) $this->config->get('app.frontend_url'), '/').'/apple-touch-icon.png';
    }

    private function googleClassSuffix(LoyaltyProgramDomainObject $program): string
    {
        return 'picha-fidelite-'.$program->getId();
    }

    private function findPass(?string $id): ?MobilePass
    {
        if ($id === null) {
            return null;
        }

        return MobilePass::query()->find($id);
    }

    private function safely(LoyaltyCardDomainObject $card, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->logger->error('Loyalty wallet pass update failed', [
                'loyalty_card_id' => $card->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
