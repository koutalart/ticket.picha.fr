<?php

namespace Tests\Unit\Services\Domain\User;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Domain\User\EmailConfirmationService;
use HiEvents\Services\Domain\User\VerifyUserEmailService;
use HiEvents\Services\Infrastructure\Encryption\EncryptedPayloadService;
use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class EmailConfirmationFromLinkTest extends TestCase
{
    private EncryptedPayloadService $payloads;

    private UserRepositoryInterface $users;

    private AccountUserRepositoryInterface $accountUsers;

    private VerifyUserEmailService $verify;

    private EmailConfirmationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payloads = m::mock(EncryptedPayloadService::class);
        $this->users = m::mock(UserRepositoryInterface::class);
        $this->accountUsers = m::mock(AccountUserRepositoryInterface::class);
        $this->verify = m::mock(VerifyUserEmailService::class);
        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->service = new EmailConfirmationService(
            m::mock(Mailer::class),
            $this->payloads,
            $this->users,
            $database,
            m::mock(EmailVerificationCodeService::class),
            $this->verify,
            m::mock(EventRepositoryInterface::class),
            $this->accountUsers,
        );
    }

    public function test_link_with_account_verifies_that_account_without_a_session(): void
    {
        $user = (new UserDomainObject)->setId(12);
        $this->payloads->shouldReceive('decryptPayload')->with('tok')->andReturn(['id' => 12, 'account_id' => 8]);
        $this->users->shouldReceive('findById')->with(12)->andReturn($user);
        $this->accountUsers->shouldNotReceive('findWhere');
        $this->verify->shouldReceive('markEmailAsVerified')->once()->with($user, 8);

        $this->service->confirmEmailAddressFromLink('tok');
        $this->assertTrue(true);
    }

    public function test_older_link_verifies_every_account_of_the_user(): void
    {
        $user = (new UserDomainObject)->setId(12);
        $this->payloads->shouldReceive('decryptPayload')->andReturn(['id' => 12]);
        $this->users->shouldReceive('findById')->andReturn($user);
        $this->accountUsers->shouldReceive('findWhere')->with(['user_id' => 12])->andReturn(collect([
            (new AccountUserDomainObject)->setAccountId(8),
            (new AccountUserDomainObject)->setAccountId(9),
        ]));
        $this->verify->shouldReceive('markEmailAsVerified')->once()->with($user, 8);
        $this->verify->shouldReceive('markEmailAsVerified')->once()->with($user, 9);

        $this->service->confirmEmailAddressFromLink('tok');
        $this->assertTrue(true);
    }

    public function test_link_for_a_user_without_account_is_rejected(): void
    {
        $this->payloads->shouldReceive('decryptPayload')->andReturn(['id' => 12]);
        $this->users->shouldReceive('findById')->andReturn((new UserDomainObject)->setId(12));
        $this->accountUsers->shouldReceive('findWhere')->andReturn(collect());

        $this->expectException(ResourceNotFoundException::class);

        $this->service->confirmEmailAddressFromLink('tok');
    }
}
