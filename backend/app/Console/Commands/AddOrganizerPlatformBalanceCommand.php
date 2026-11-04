<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidPlatformBalanceException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Organizer\PlatformBalance\OrganizerPlatformBalanceService;
use Illuminate\Console\Command;

/**
 * Records an amount an organizer owes to PICHA. It is withheld on the organizer part of their next
 * Stripe Connect payments, never more than what they would have received.
 * Example: php artisan platform:balance-add 4 57.88 --name-contains="Innocent" --reason="Options sérénité versées avant le 04/11" --invoice=F-2026-012 --dry-run
 */
class AddOrganizerPlatformBalanceCommand extends Command
{
    protected $signature = 'platform:balance-add
        {organizer : Id of the organizer}
        {amount : Amount owed, e.g. 57.88}
        {--reason= : Required: why the organizer owes this amount}
        {--invoice= : Reference of the invoice sent to the organizer}
        {--currency=EUR : Currency of the amount}
        {--name-contains= : Safety check: part of the organizer name}
        {--dry-run : Show what would be recorded, change nothing}';

    protected $description = 'Record an amount owed by an organizer to the platform, withheld on their next payments';

    public function handle(OrganizerRepositoryInterface $organizerRepository, OrganizerPlatformBalanceService $balanceService): int
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $organizerRepository->findFirstWhere(['id' => (int) $this->argument('organizer')]);

        if ($organizer === null) {
            $this->error('Organizer not found: nothing changed.');

            return self::FAILURE;
        }

        $expected = trim((string) $this->option('name-contains'));
        if ($expected !== '' && ! str_contains(mb_strtolower($organizer->getName()), mb_strtolower($expected))) {
            $this->error(sprintf('The name "%s" does not contain "%s": nothing changed.', $organizer->getName(), $expected));

            return self::FAILURE;
        }

        $amount = round((float) str_replace(',', '.', (string) $this->argument('amount')), 2);
        $currency = strtoupper((string) $this->option('currency'));
        $reason = trim((string) $this->option('reason'));
        $invoice = $this->option('invoice');
        $alreadyOwed = $balanceService->getRemainingTotal($organizer->getId(), $currency);

        $this->line(sprintf('Organizer %d "%s"', $organizer->getId(), $organizer->getName()));
        $this->line(sprintf('Amount owed: %.2f %s (already owed: %.2f %s)', $amount, $currency, $alreadyOwed, $currency));
        $this->line(sprintf('Reason: %s', $reason === '' ? '(missing)' : $reason));
        $this->line(sprintf('Invoice: %s', $invoice ?: '(none)'));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing recorded.');

            return $amount > 0 && $reason !== '' ? self::SUCCESS : self::FAILURE;
        }

        try {
            $balance = $balanceService->addBalance($organizer->getId(), $amount, $currency, $reason, $invoice);
        } catch (InvalidPlatformBalanceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Recorded: balance %d, %.2f %s to withhold on the next payments.', $balance->getId(), $amount, $currency));

        return self::SUCCESS;
    }
}
