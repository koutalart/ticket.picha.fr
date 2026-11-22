<?php

namespace Tests\Unit\Services\Application\Handlers\Account;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\EmailAlreadyExists;
use HiEvents\Repository\Interfaces\AccountConfigurationRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\CreateManagedOrganizationHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateManagedOrganizationDTO;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Domain\Account\AccountUserAssociationService;
use HiEvents\Services\Domain\User\EmailConfirmationService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Hashing\HashManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateManagedOrganizationHandlerTest extends TestCase
{
    private MockInterface $accounts;

    private MockInterface $users;

    private MockInterface $accountUsers;

    private MockInterface $configurations;

    private MockInterface $organizerSettings;

    private MockInterface $organizers;

    private CreateManagedOrganizationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounts = Mockery::mock(AccountRepositoryInterface::class);
        $this->users = Mockery::mock(UserRepositoryInterface::class);
        $this->accountUsers = Mockery::mock(AccountUserRepositoryInterface::class);
        $this->configurations = Mockery::mock(AccountConfigurationRepositoryInterface::class);
        $this->organizerSettings = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->organizers = Mockery::mock(CreateOrganizerHandler::class);

        $hash = Mockery::mock(HashManager::class);
        $hash->shouldReceive('make')->andReturn('hash');

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->handler = new CreateManagedOrganizationHandler(
            $this->accounts, $this->users, $this->accountUsers, $this->configurations, $this->organizerSettings,
            new AccountUserAssociationService($this->accountUsers), $this->organizers, $hash, $database,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function data(): CreateManagedOrganizationDTO
    {
        return new CreateManagedOrganizationDTO(
            organizer_name: 'CCI Mayotte',
            email: 'Contact@CCI.yt',
            first_name: 'Catherine',
            last_name: 'Mkadara',
            phone: '0639000000',
            timezone: 'Indian/Mayotte',
            currency: 'EUR',
            locale: 'fr',
        );
    }

    public function test_it_creates_account_owner_and_organizer_with_the_fee_paid_by_the_buyer_and_sends_nothing(): void
    {
        $this->app->instance(EmailConfirmationService::class, Mockery::mock(EmailConfirmationService::class)->shouldNotReceive('sendConfirmation')->getMock());

        $this->users->shouldReceive('findFirstWhere')->with(['email' => 'contact@cci.yt'])->andReturnNull();
        $this->configurations->shouldReceive('findFirstWhere')->with(['is_system_default' => true])
            ->andReturn((new AccountConfigurationDomainObject)->setId(1));

        $this->accounts->shouldReceive('create')->once()
            ->withArgs(fn (array $data) => $data['timezone'] === 'Indian/Mayotte' && $data['currency_code'] === 'EUR'
                && $data['email'] === 'contact@cci.yt' && $data['account_configuration_id'] === 1)
            ->andReturn((new AccountDomainObject)->setId(14));
        $this->users->shouldReceive('create')->once()
            ->withArgs(fn (array $data) => $data['email'] === 'contact@cci.yt' && $data['locale'] === 'fr' && $data['email_verified_at'] !== null)
            ->andReturn((new UserDomainObject)->setId(30));
        $this->accountUsers->shouldReceive('create')->once()
            ->withArgs(fn (array $data) => $data['user_id'] === 30 && $data['account_id'] === 14
                && $data['role'] === Role::ADMIN->name && $data['is_account_owner'] === true && $data['status'] === 'ACTIVE')
            ->andReturn(new AccountUserDomainObject);
        $this->organizers->shouldReceive('handle')->once()
            ->withArgs(fn (CreateOrganizerDTO $dto) => $dto->account_id === 14 && $dto->name === 'CCI Mayotte'
                && $dto->timezone === 'Indian/Mayotte' && $dto->phone === '0639000000')
            ->andReturn((new OrganizerDomainObject)->setId(18));
        $this->organizerSettings->shouldReceive('updateWhere')->once()
            ->with(['default_pass_platform_fee_to_buyer' => true], ['organizer_id' => 18])
            ->andReturn(1);

        $result = $this->handler->handle($this->data());

        $this->assertSame(18, $result->organizer->getId());
        $this->assertSame(14, $result->account->getId());
        $this->assertSame(30, $result->user->getId());
    }

    public function test_an_email_that_already_owns_an_account_is_refused(): void
    {
        $this->users->shouldReceive('findFirstWhere')->andReturn((new UserDomainObject)->setId(30));
        $this->accountUsers->shouldReceive('findFirstWhere')->with(['user_id' => 30, 'is_account_owner' => true])
            ->andReturn(new AccountUserDomainObject);
        $this->accounts->shouldNotReceive('create');

        $this->expectException(EmailAlreadyExists::class);

        $this->handler->handle($this->data());
    }
}
