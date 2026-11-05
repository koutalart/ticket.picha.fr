<?php

namespace HiEvents\Services\Domain\Organizer\PlatformBalance;

use HiEvents\DomainObjects\Generated\OrganizerPlatformBalanceDeductionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerPlatformBalanceDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerPlatformBalanceDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\InvalidPlatformBalanceException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerPlatformBalanceDeductionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerPlatformBalanceRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderOrganizerShareService;
use HiEvents\Values\MoneyValue;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * PICHA: an amount an organizer owes to the platform is withheld on their next Stripe Connect payments,
 * added to the application fee. The withholding never exceeds the organizer part of the order
 * (tickets and products), so the organizer always keeps at least 0 €. The balance only goes down
 * when the payment succeeded, once per order. Nothing is withheld before the organizer validated the
 * amount (who and when are recorded).
 */
class OrganizerPlatformBalanceService
{
    public function __construct(
        private readonly OrganizerPlatformBalanceRepositoryInterface          $balanceRepository,
        private readonly OrganizerPlatformBalanceDeductionRepositoryInterface $deductionRepository,
        private readonly OrderOrganizerShareService                           $organizerShareService,
        private readonly EventRepositoryInterface                             $eventRepository,
        private readonly LoggerInterface                                      $logger,
    )
    {
    }

    /**
     * Amount to add to the application fee of this order, before the payment.
     */
    public function calculateWithholding(OrderDomainObject $order): MoneyValue
    {
        $currency = $order->getCurrency();
        $organizerId = $this->getOrganizerId($order);

        if ($organizerId === null) {
            return MoneyValue::zero($currency);
        }

        $remaining = $this->toCents($this->balanceRepository->getRemainingTotal($organizerId, $currency));
        if ($remaining <= 0) {
            return MoneyValue::zero($currency);
        }

        $organizerPart = $this->toCents($this->organizerShareService->getShare($order)->organizerSales);

        return MoneyValue::fromMinorUnit(max(0, min($remaining, $organizerPart)), $currency);
    }

    /**
     * Records the withholding of a succeeded payment and lowers the balance. Idempotent: an order is only
     * recorded once. Call it inside the transaction of the payment confirmation.
     *
     * @return float the amount taken off the balances
     */
    public function recordWithholding(StripePaymentDomainObject $stripePayment, OrderDomainObject $order): float
    {
        $withheld = $stripePayment->getPlatformBalanceWithheld();
        $organizerId = $this->getOrganizerId($order);

        if ($withheld <= 0 || $organizerId === null) {
            return 0.0;
        }

        $alreadyRecorded = $this->deductionRepository->findFirstWhere([
            OrganizerPlatformBalanceDeductionDomainObjectAbstract::ORDER_ID => $order->getId(),
        ]);

        if ($alreadyRecorded !== null) {
            return 0.0;
        }

        $left = $withheld;

        /** @var OrganizerPlatformBalanceDomainObject $balance */
        foreach ($this->balanceRepository->findOpenForUpdate($organizerId, $order->getCurrency()) as $balance) {
            if ($left <= 0) {
                break;
            }

            $remaining = $this->toCents($balance->getRemainingAmount());
            $taken = min($left, $remaining);
            if ($taken <= 0) {
                continue;
            }

            $newRemaining = $remaining - $taken;
            $this->balanceRepository->updateFromArray($balance->getId(), [
                OrganizerPlatformBalanceDomainObjectAbstract::REMAINING_AMOUNT => $this->fromCents($newRemaining),
                OrganizerPlatformBalanceDomainObjectAbstract::SETTLED_AT => $newRemaining === 0 ? Carbon::now()->toDateTimeString() : null,
            ]);

            $this->deductionRepository->create([
                OrganizerPlatformBalanceDeductionDomainObjectAbstract::ORGANIZER_PLATFORM_BALANCE_ID => $balance->getId(),
                OrganizerPlatformBalanceDeductionDomainObjectAbstract::ORDER_ID => $order->getId(),
                OrganizerPlatformBalanceDeductionDomainObjectAbstract::STRIPE_PAYMENT_ID => $stripePayment->getId(),
                OrganizerPlatformBalanceDeductionDomainObjectAbstract::AMOUNT => $this->fromCents($taken),
            ]);

            $left -= $taken;
        }

        if ($left > 0) {
            $this->logger->warning('Platform balance: more was withheld than the organizer still owed, give it back to the organizer', [
                'order_id' => $order->getId(),
                'organizer_id' => $organizerId,
                'withheld' => $this->fromCents($withheld),
                'excess' => $this->fromCents($left),
            ]);
        }

        return $this->fromCents($withheld - $left);
    }

    /**
     * @throws InvalidPlatformBalanceException
     */
    public function addBalance(
        int     $organizerId,
        float   $amount,
        string  $currency,
        string  $reason,
        ?string $invoiceReference = null,
    ): OrganizerPlatformBalanceDomainObject
    {
        $cents = $this->toCents($amount);

        if ($cents <= 0) {
            throw new InvalidPlatformBalanceException(__('The amount owed must be greater than zero.'));
        }

        if (trim($reason) === '') {
            throw new InvalidPlatformBalanceException(__('Give the reason of the amount owed.'));
        }

        return $this->balanceRepository->create([
            OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            OrganizerPlatformBalanceDomainObjectAbstract::CURRENCY => strtoupper($currency),
            OrganizerPlatformBalanceDomainObjectAbstract::INITIAL_AMOUNT => $this->fromCents($cents),
            OrganizerPlatformBalanceDomainObjectAbstract::REMAINING_AMOUNT => $this->fromCents($cents),
            OrganizerPlatformBalanceDomainObjectAbstract::REASON => trim($reason),
            OrganizerPlatformBalanceDomainObjectAbstract::INVOICE_REFERENCE => $invoiceReference !== null && trim($invoiceReference) !== ''
                ? trim($invoiceReference)
                : null,
        ]);
    }

    /**
     * @throws InvalidPlatformBalanceException
     */
    public function validateBalance(OrganizerPlatformBalanceDomainObject $balance, string $validatedBy): OrganizerPlatformBalanceDomainObject
    {
        if (trim($validatedBy) === '') {
            throw new InvalidPlatformBalanceException(__('Say who validated the amount owed and how.'));
        }

        return $this->balanceRepository->updateFromArray($balance->getId(), [
            OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_VALIDATED_AT => Carbon::now()->toDateTimeString(),
            OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_VALIDATED_BY => trim($validatedBy),
        ]);
    }

    public function suspendBalance(OrganizerPlatformBalanceDomainObject $balance): OrganizerPlatformBalanceDomainObject
    {
        return $this->balanceRepository->updateFromArray($balance->getId(), [
            OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_VALIDATED_AT => null,
            OrganizerPlatformBalanceDomainObjectAbstract::ORGANIZER_VALIDATED_BY => null,
        ]);
    }

    public function getRemainingTotal(int $organizerId, string $currency): float
    {
        return $this->balanceRepository->getRemainingTotal($organizerId, $currency);
    }

    private function getOrganizerId(OrderDomainObject $order): ?int
    {
        $organizerId = $order->getEvent()?->getOrganizerId();

        if ($organizerId !== null) {
            return $organizerId;
        }

        return $this->eventRepository->findFirstWhere(['id' => $order->getEventId()])?->getOrganizerId();
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function fromCents(int $cents): float
    {
        return $cents / 100;
    }
}
