<?php

namespace Tests\Unit\Services\Domain\Assistant;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\AssistantActionStatus;
use HiEvents\Exceptions\AssistantConfirmationException;
use HiEvents\Repository\Interfaces\AssistantActionRepositoryInterface;
use HiEvents\Services\Domain\Assistant\AssistantActionJournal;
use HiEvents\Services\Domain\Assistant\DTO\AssistantActionContextDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AssistantActionJournalTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantActionJournal $journal;

    private AssistantActionRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journal = $this->app->make(AssistantActionJournal::class);
        $this->repository = $this->app->make(AssistantActionRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function context(array $params = ['commande' => '12', 'montant' => '15'], string $command = 'anicka:rembourser', string $by = 'Anicka'): AssistantActionContextDTO
    {
        return new AssistantActionContextDTO(
            command: $command,
            level: 2,
            requestedBy: $by,
            params: $params,
        );
    }

    public function test_a_level_two_action_waits_for_its_token_then_runs_once(): void
    {
        $request = $this->journal->requestConfirmation($this->context(), 'Rembourser 15 EUR');

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $request->token);
        $pending = $this->repository->findById($request->actionId);
        $this->assertSame(AssistantActionStatus::AWAITING_CONFIRMATION->value, $pending->getStatus());
        $this->assertNotSame($request->token, $pending->getConfirmationTokenHash());

        $confirmed = $this->journal->consumeConfirmation(
            $this->context(['montant' => '15', 'commande' => '12'], by: 'Jo, SMS'),
            strtolower($request->token),
        );

        $this->assertSame($request->actionId, $confirmed->getId());
        $this->assertSame(AssistantActionStatus::CONFIRMED->value, $confirmed->getStatus());
        $this->assertSame('Anicka | confirmed by Jo, SMS', $confirmed->getRequestedBy());

        $this->expectException(AssistantConfirmationException::class);
        $this->journal->consumeConfirmation($this->context(), $request->token);
    }

    public function test_a_token_does_not_confirm_other_values(): void
    {
        $request = $this->journal->requestConfirmation($this->context(), 'Rembourser 15 EUR');

        $this->expectException(AssistantConfirmationException::class);
        $this->journal->consumeConfirmation($this->context(['commande' => '12', 'montant' => '150']), $request->token);
    }

    public function test_a_token_does_not_confirm_another_command(): void
    {
        $request = $this->journal->requestConfirmation($this->context(), 'Rembourser 15 EUR');

        $this->expectException(AssistantConfirmationException::class);
        $this->journal->consumeConfirmation($this->context(command: 'anicka:annuler-commande'), $request->token);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $request = $this->journal->requestConfirmation($this->context(), 'Rembourser 15 EUR');

        Carbon::setTestNow(Carbon::now()->addHours(AssistantActionJournal::TOKEN_TTL_HOURS)->addMinute());

        $this->expectException(AssistantConfirmationException::class);
        $this->journal->consumeConfirmation($this->context(), $request->token);
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->expectException(AssistantConfirmationException::class);
        $this->journal->consumeConfirmation($this->context(), 'ZZZZZZZZ');
    }

    public function test_the_result_and_the_failures_are_kept(): void
    {
        $done = $this->journal->start($this->context(), 'Ouvrir la vente');
        $this->journal->markExecuted($done->getId(), ['tarif_id' => 91]);

        $failed = $this->journal->start($this->context(), 'Ouvrir la vente');
        $this->journal->markFailed($failed->getId(), 'Stripe indisponible');

        $refused = $this->journal->refuse($this->context(), 'Événement d\'un autre organisateur');

        $done = $this->repository->findById($done->getId());
        $this->assertSame(AssistantActionStatus::EXECUTED->value, $done->getStatus());
        $this->assertSame(['tarif_id' => 91], $done->getResult());
        $this->assertNotNull($done->getExecutedAt());

        $this->assertSame('Stripe indisponible', $this->repository->findById($failed->getId())->getError());
        $this->assertSame(AssistantActionStatus::REFUSED->value, $this->repository->findById($refused->getId())->getStatus());
    }
}
