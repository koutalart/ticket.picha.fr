<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerPlatformBalanceDomainObject;
use HiEvents\Exceptions\InvalidPlatformBalanceException;
use HiEvents\Repository\Interfaces\OrganizerPlatformBalanceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Organizer\PlatformBalance\OrganizerPlatformBalanceService;
use Illuminate\Console\Command;

/**
 * An amount owed is only withheld once the organizer validated it. --suspend stops the withholding again.
 * Example: php artisan platform:balance-validate 1 --by="Innocent, SMS du 05/11" --name-contains="Innocent" --dry-run
 */
class ValidateOrganizerPlatformBalanceCommand extends Command
{
    protected $signature = 'platform:balance-validate
        {balance : Id of the amount owed (organizer_platform_balances)}
        {--by= : Required to validate: who validated it and how, e.g. "Innocent, SMS du 05/11"}
        {--suspend : Stop withholding this amount until it is validated again}
        {--name-contains= : Safety check: part of the organizer name}
        {--dry-run : Show the amount owed, change nothing}';

    protected $description = 'Record that the organizer validated an amount owed, so it is withheld on their next payments';

    public function handle(
        OrganizerPlatformBalanceRepositoryInterface $balanceRepository,
        OrganizerRepositoryInterface                $organizerRepository,
        OrganizerPlatformBalanceService             $balanceService,
    ): int
    {
        /** @var OrganizerPlatformBalanceDomainObject|null $balance */
        $balance = $balanceRepository->findFirstWhere(['id' => (int) $this->argument('balance')]);

        if ($balance === null) {
            $this->error('Amount owed not found: nothing changed.');

            return self::FAILURE;
        }

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $organizerRepository->findFirstWhere(['id' => $balance->getOrganizerId()]);
        $name = $organizer?->getName() ?? '';

        $expected = trim((string) $this->option('name-contains'));
        if ($expected !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($expected))) {
            $this->error(sprintf('The name "%s" does not contain "%s": nothing changed.', $name, $expected));

            return self::FAILURE;
        }

        $this->line(sprintf('Balance %d, organizer %d "%s"', $balance->getId(), $balance->getOrganizerId(), $name));
        $this->line(sprintf('Owed: %.2f %s left of %.2f %s', $balance->getRemainingAmount(), $balance->getCurrency(), $balance->getInitialAmount(), $balance->getCurrency()));
        $this->line(sprintf('Reason: %s', $balance->getReason()));
        $this->line(sprintf('Validated: %s', $balance->getOrganizerValidatedAt() === null
            ? 'no (suspended)'
            : sprintf('%s by %s', $balance->getOrganizerValidatedAt(), $balance->getOrganizerValidatedBy())));

        $suspend = (bool) $this->option('suspend');
        $validatedBy = trim((string) $this->option('by'));

        if (! $suspend && $validatedBy === '') {
            $this->error('Say who validated it with --by: nothing changed.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        if ($suspend) {
            $balanceService->suspendBalance($balance);
            $this->info(sprintf('Suspended: balance %d is no longer withheld.', $balance->getId()));

            return self::SUCCESS;
        }

        try {
            $balanceService->validateBalance($balance, $validatedBy);
        } catch (InvalidPlatformBalanceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Validated: balance %d is withheld on the next payments.', $balance->getId()));

        return self::SUCCESS;
    }
}
