<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Enums\PromoCodeDiscountTypeEnum;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Services\Application\Handlers\PromoCode\CreatePromoCodeHandler;
use HiEvents\Services\Application\Handlers\PromoCode\DTO\UpsertPromoCodeDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 1. Example: php artisan anicka:creer-code-promo --organisateur=4 --evenement=7 --code=NOEL10 --type=pourcentage --remise=10 --expire="2026-12-24 23:59"
 */
class CreatePromoCodeAssistantCommand extends AbstractAssistantCommand
{
    private const TYPES = [
        'pourcentage' => PromoCodeDiscountTypeEnum::PERCENTAGE,
        'montant' => PromoCodeDiscountTypeEnum::FIXED,
        'aucune' => PromoCodeDiscountTypeEnum::NONE,
    ];

    protected $signature = 'anicka:creer-code-promo
        {--evenement= : Event id (required)}
        {--code= : The code buyers type, letters, digits, - or _ (required)}
        {--type=pourcentage : pourcentage, montant (fixed amount off each ticket) or aucune (only unlocks hidden tickets)}
        {--remise= : Percentage or amount off, required unless --type=aucune}
        {--tarifs= : Product ids the code applies to, separated by commas (all when empty)}
        {--expire= : Last moment the code works, "Y-m-d H:i" in the event time zone}
        {--utilisations-max= : Maximum number of uses}';

    protected $description = 'Assistant, level 1: create a promo code for an event';

    protected function level(): int
    {
        return 1;
    }

    protected function validateInput(): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{2,50}$/', $this->requiredString('code')) !== 1) {
            throw new AssistantActionRefusedException(__('The code must have 2 to 50 letters, digits, - or _.'));
        }

        $type = $this->type();
        $discount = $this->amount('remise', $type !== PromoCodeDiscountTypeEnum::NONE);

        if ($type !== PromoCodeDiscountTypeEnum::NONE && $discount <= 0) {
            throw new AssistantActionRefusedException(__('The discount must be greater than zero.'));
        }

        if ($type === PromoCodeDiscountTypeEnum::PERCENTAGE && $discount > 100) {
            throw new AssistantActionRefusedException(__('A percentage cannot be over 100.'));
        }

        $this->idList('tarifs');
        $this->optionalId('utilisations-max');
        $this->localDateTime('expire');
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $type = $this->type();
        $discount = match ($type) {
            PromoCodeDiscountTypeEnum::PERCENTAGE => $this->amount('remise').' %',
            PromoCodeDiscountTypeEnum::FIXED => $this->money($this->amount('remise'), $scope->event->getCurrency()),
            default => __('no discount'),
        };

        return __('Create the promo code :code (:discount) for ":event".', [
            'code' => strtoupper($this->requiredString('code')),
            'discount' => $discount,
            'event' => $scope->event->getTitle(),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $promoCode = $this->laravel->make(CreatePromoCodeHandler::class)->handle($scope->eventId(), new UpsertPromoCodeDTO(
            code: strtolower($this->requiredString('code')),
            event_id: $scope->eventId(),
            applicable_product_ids: $this->idList('tarifs'),
            discount_type: $this->type(),
            discount: $this->type() === PromoCodeDiscountTypeEnum::NONE ? null : $this->amount('remise'),
            expiry_date: $this->localDateTime('expire'),
            max_allowed_usages: $this->optionalId('utilisations-max'),
        ));

        return [
            'code_promo_id' => $promoCode->getId(),
            'code' => strtoupper($promoCode->getCode()),
            'expire_le' => $promoCode->getExpiryDate(),
        ];
    }

    private function type(): PromoCodeDiscountTypeEnum
    {
        return self::TYPES[$this->choiceOption('type', array_keys(self::TYPES), 'pourcentage')];
    }
}
