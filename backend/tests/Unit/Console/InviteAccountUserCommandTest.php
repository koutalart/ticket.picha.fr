<?php

namespace Tests\Unit\Console;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Mail\User\UserInvited;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use HiEvents\Services\Domain\User\SendUserInvitationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class InviteAccountUserCommandTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private User $owner;

    private int $accountId;

    private int $organizerId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = $this->createUserWithAccount();
        $this->owner->update(['locale' => 'en']);
        $this->accountId = $this->owner->accounts()->first()->id;

        $this->organizerId = Organizer::create([
            'account_id' => $this->accountId,
            'name' => 'Innocent Event',
            'email' => 'organizer-'.uniqid().'@example.test',
            'currency' => 'EUR',
            'timezone' => 'Indian/Mayotte',
        ])->id;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function email(): string
    {
        return 'invite-'.uniqid().'@example.com';
    }

    public function test_sends_the_settings_invitation_in_french_on_behalf_of_the_account_owner(): void
    {
        App::setLocale('en');
        $email = $this->email();

        $this->artisan('account:invite-user', [
            'organizer' => $this->organizerId,
            'email' => strtoupper($email),
            '--first-name' => 'Aminat',
        ])
            ->expectsOutputToContain('Invitation sent.')
            ->expectsOutputToContain('INVITED user_id=')
            ->assertSuccessful();

        $user = User::where('email', $email)->firstOrFail();
        $membership = DB::table('account_users')->where('user_id', $user->id)->where('account_id', $this->accountId)->first();

        $this->assertSame(Role::ORGANIZER->name, $membership->role);
        $this->assertSame(UserStatus::INVITED->name, $membership->status);
        $this->assertSame($this->owner->id, $membership->invited_by_user_id);
        $this->assertFalse((bool) $membership->is_account_owner);

        Mail::assertQueued(UserInvited::class, function (UserInvited $mail) use ($email) {
            $this->assertTrue($mail->hasTo($email));
            $this->assertSame('fr', $mail->locale);
            $html = $mail->render();
            $this->assertStringContainsString('Bonjour Aminat', $html);
            $this->assertStringContainsString("Accepter l'invitation", $html);

            return true;
        });
    }

    public function test_status_line_is_stable_for_scripts(): void
    {
        $email = $this->email();

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email])->assertSuccessful();

        $userId = User::where('email', $email)->value('id');

        Mail::assertQueued(UserInvited::class, 1);

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email])
            ->expectsOutput(sprintf(
                'ALREADY_INVITED user_id=%d account_id=%d organizer_id=%d email=%s role=ORGANIZER inviter_id=%d status=INVITED',
                $userId, $this->accountId, $this->organizerId, $email, $this->owner->id,
            ))
            ->assertSuccessful();

        Mail::assertQueued(UserInvited::class, 1);
    }

    public function test_already_member_sends_nothing_and_succeeds(): void
    {
        $invitationService = Mockery::mock(SendUserInvitationService::class);
        $invitationService->shouldNotReceive('sendInvitation');
        $this->app->instance(SendUserInvitationService::class, $invitationService);

        $this->artisan('account:invite-user', [
            'organizer' => $this->organizerId,
            'email' => $this->owner->email,
        ])
            ->expectsOutputToContain('already a member of the account')
            ->expectsOutputToContain(sprintf('ALREADY_MEMBER user_id=%d account_id=%d', $this->owner->id, $this->accountId))
            ->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_pending_invitation_is_resent_only_with_resend(): void
    {
        $email = $this->email();
        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email])->assertSuccessful();

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email, '--resend' => true])
            ->expectsOutputToContain('INVITATION_RESENT user_id=')
            ->assertSuccessful();

        Mail::assertQueued(UserInvited::class, 2);
        $this->assertSame(1, DB::table('account_users')->where('account_id', $this->accountId)->where('user_id', User::where('email', $email)->value('id'))->count());
    }

    public function test_admin_role_is_refused_without_allow_admin(): void
    {
        $email = $this->email();

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email, '--role' => 'admin'])
            ->expectsOutputToContain('--allow-admin')
            ->expectsOutput('ERROR reason=role_not_allowed')
            ->assertFailed();

        Mail::assertNothingQueued();
        $this->assertFalse(User::where('email', $email)->exists());
    }

    public function test_admin_role_is_accepted_with_allow_admin(): void
    {
        $email = $this->email();

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email, '--role' => 'ADMIN', '--allow-admin' => true])
            ->expectsOutputToContain('role=ADMIN')
            ->assertSuccessful();

        $userId = User::where('email', $email)->value('id');
        $this->assertSame(Role::ADMIN->name, DB::table('account_users')->where('user_id', $userId)->value('role'));
    }

    public function test_superadmin_and_box_office_roles_are_always_refused(): void
    {
        foreach (['SUPERADMIN', 'BOX_OFFICE_OPERATOR'] as $role) {
            $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $this->email(), '--role' => $role, '--allow-admin' => true])
                ->expectsOutput('ERROR reason=role_not_allowed')
                ->assertFailed();
        }

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $this->email(), '--role' => 'BOSS'])
            ->expectsOutput('ERROR reason=invalid_role')
            ->assertFailed();

        Mail::assertNothingQueued();
    }

    public function test_dry_run_shows_the_plan_and_changes_nothing(): void
    {
        $email = $this->email();

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email, '--dry-run' => true])
            ->expectsOutputToContain('Organizer: Innocent Event (#'.$this->organizerId.')')
            ->expectsOutputToContain('Invited by: '.$this->owner->email.' (#'.$this->owner->id.', account owner)')
            ->expectsOutput(sprintf(
                'DRY_RUN user_id=- account_id=%d organizer_id=%d email=%s role=ORGANIZER inviter_id=%d',
                $this->accountId, $this->organizerId, $email, $this->owner->id,
            ))
            ->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertFalse(User::where('email', $email)->exists());
    }

    public function test_unknown_organizer_fails(): void
    {
        $this->artisan('account:invite-user', ['organizer' => 999999999, 'email' => $this->email()])
            ->expectsOutput('ERROR reason=organizer_not_found')
            ->assertFailed();

        Mail::assertNothingQueued();
    }

    public function test_invalid_email_fails(): void
    {
        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => 'not-an-email'])
            ->expectsOutput('ERROR reason=invalid_email')
            ->assertFailed();

        Mail::assertNothingQueued();
    }

    public function test_falls_back_to_the_first_active_admin_when_the_account_has_no_owner(): void
    {
        DB::table('account_users')->where('account_id', $this->accountId)->update(['is_account_owner' => false]);
        $email = $this->email();

        $this->artisan('account:invite-user', ['organizer' => $this->organizerId, 'email' => $email])
            ->expectsOutputToContain('first active admin')
            ->assertSuccessful();

        $userId = User::where('email', $email)->value('id');
        $this->assertSame($this->owner->id, DB::table('account_users')->where('user_id', $userId)->value('invited_by_user_id'));
    }
}
