<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Account;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\EmailAlreadyExists;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AccountConfigurationRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateManagedOrganizationDTO;
use HiEvents\Services\Application\Handlers\Account\DTO\ManagedOrganizationDTO;
use HiEvents\Services\Application\Handlers\Account\Exceptions\AccountConfigurationDoesNotExist;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Domain\Account\AccountUserAssociationService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Account, owner and organizer created by PICHA for a client (assistant command anicka:creer-organisation).
 * Unlike a sign-up nothing is sent: the client gets access later, when Jo decides (invitation or password reset).
 * The platform fee is always paid by the buyer.
 */
class CreateManagedOrganizationHandler
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly AccountUserRepositoryInterface $accountUserRepository,
        private readonly AccountConfigurationRepositoryInterface $accountConfigurationRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly AccountUserAssociationService $accountUserAssociationService,
        private readonly CreateOrganizerHandler $createOrganizerHandler,
        private readonly HashManager $hashManager,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws EmailAlreadyExists
     * @throws AccountConfigurationDoesNotExist
     * @throws Throwable
     */
    public function handle(CreateManagedOrganizationDTO $data): ManagedOrganizationDTO
    {
        $email = strtolower(trim($data->email));

        $this->ensureEmailIsFree($email);
        $configurationId = $this->defaultConfigurationId();

        return $this->databaseManager->transaction(function () use ($data, $email, $configurationId) {
            /** @var AccountDomainObject $account */
            $account = $this->accountRepository->create([
                'timezone' => $data->timezone,
                'currency_code' => $data->currency,
                'name' => $data->organizer_name,
                'email' => $email,
                'short_id' => IdHelper::shortId(IdHelper::ACCOUNT_PREFIX),
                'account_verified_at' => now()->toDateTimeString(),
                'account_configuration_id' => $configurationId,
                'account_messaging_tier_id' => config('app.is_hi_events') ? 1 : 3,
            ]);

            /** @var UserDomainObject $user */
            $user = $this->existingUser($email) ?? $this->userRepository->create([
                'password' => $this->hashManager->make(Str::random(40)),
                'email' => $email,
                'first_name' => $data->first_name,
                'last_name' => $data->last_name,
                'timezone' => $data->timezone,
                'email_verified_at' => now()->toDateTimeString(),
                'locale' => $data->locale,
            ]);

            $this->accountUserAssociationService->associate(
                user: $user,
                account: $account,
                role: Role::ADMIN,
                status: UserStatus::ACTIVE,
                isAccountOwner: true,
            );

            $organizer = $this->createOrganizerHandler->handle(new CreateOrganizerDTO(
                name: $data->organizer_name,
                email: $email,
                account_id: $account->getId(),
                timezone: $data->timezone,
                currency: $data->currency,
                phone: $data->phone,
            ));

            $this->organizerSettingsRepository->updateWhere(
                attributes: ['default_pass_platform_fee_to_buyer' => true],
                where: ['organizer_id' => $organizer->getId()],
            );

            return new ManagedOrganizationDTO(account: $account, user: $user, organizer: $organizer);
        });
    }

    /**
     * @throws EmailAlreadyExists
     */
    public function ensureEmailIsFree(string $email): void
    {
        $user = $this->existingUser(strtolower(trim($email)));

        if ($user === null) {
            return;
        }

        $owner = $this->accountUserRepository->findFirstWhere([
            'user_id' => $user->getId(),
            'is_account_owner' => true,
        ]);

        if ($owner !== null) {
            throw new EmailAlreadyExists(__('There is already an account associated with this email. Please log in instead.'));
        }
    }

    private function existingUser(string $email): ?UserDomainObject
    {
        /** @var UserDomainObject|null $user */
        $user = $this->userRepository->findFirstWhere(['email' => $email]);

        return $user;
    }

    /**
     * @throws AccountConfigurationDoesNotExist
     */
    private function defaultConfigurationId(): int
    {
        /** @var AccountConfigurationDomainObject|null $configuration */
        $configuration = $this->accountConfigurationRepository->findFirstWhere(['is_system_default' => true]);

        if ($configuration === null) {
            throw new AccountConfigurationDoesNotExist(__('There is no default account configuration available'));
        }

        return $configuration->getId();
    }
}
