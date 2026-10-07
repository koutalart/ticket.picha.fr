<?php

namespace Tests\Unit\Services\Application\Handlers\User;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Mail\User\UserInvited;
use HiEvents\Services\Application\Handlers\User\CreateUserHandler;
use HiEvents\Services\Application\Handlers\User\DTO\CreateUserDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class InvitedUserLocaleTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_invited_user_gets_the_inviter_locale_whatever_the_current_locale(): void
    {
        Mail::fake();
        $inviter = $this->createUserWithAccount();
        $inviter->update(['locale' => 'fr']);
        App::setLocale('en');

        $accountId = $inviter->accounts()->first()->id;

        $invited = app(CreateUserHandler::class)->handle(new CreateUserDTO(
            first_name: 'Aminat',
            last_name: null,
            email: 'invite-'.uniqid().'@example.com',
            invited_by: $inviter->id,
            account_id: $accountId,
            role: Role::ORGANIZER,
        ));

        $this->assertSame('fr', $invited->getLocale());

        Mail::assertQueued(UserInvited::class, function (UserInvited $mail) {
            $this->assertSame('fr', $mail->locale);
            $html = $mail->render();
            $this->assertStringContainsString('Bonjour Aminat', $html);
            $this->assertStringContainsString("Accepter l'invitation", $html);

            return true;
        });
    }
}
