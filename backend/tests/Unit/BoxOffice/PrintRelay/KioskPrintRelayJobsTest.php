<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice\PrintRelay;

use Carbon\Carbon;
use HiEvents\DomainObjects\KioskPrintJobDomainObject;
use HiEvents\DomainObjects\KioskPrintRelayDomainObject;
use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Exceptions\InvalidKioskPrintRelayTokenException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;
use HiEvents\Repository\Interfaces\KioskPrintRelayRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\AcknowledgeKioskPrintJobHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\AcknowledgeKioskPrintJobDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\GetPendingKioskPrintJobsHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\PurgeKioskPrintJobsHandler;
use HiEvents\Services\Domain\BoxOffice\KioskPrintRelayService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class KioskPrintRelayJobsTest extends TestCase
{
    private const TOKEN_EVENT_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private KioskPrintRelayRepositoryInterface&MockInterface $relayRepository;

    private KioskPrintJobRepositoryInterface&MockInterface $jobRepository;

    private KioskPrintRelayService $relayService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relayRepository = Mockery::mock(KioskPrintRelayRepositoryInterface::class);
        $this->jobRepository = Mockery::mock(KioskPrintJobRepositoryInterface::class);
        $this->relayService = new KioskPrintRelayService($this->relayRepository);

        $this->relayRepository->shouldReceive('findFirstWhere')
            ->with(['token_hash' => hash('sha256', self::TOKEN_EVENT_A)])
            ->andReturn((new KioskPrintRelayDomainObject)->setId(1)->setEventId(10));
        $this->relayRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pending_jobs_are_those_of_the_token_event_and_relay_is_marked_seen(): void
    {
        $jobs = new Collection([(new KioskPrintJobDomainObject)->setId(1)->setEventId(10)]);

        $this->relayRepository->shouldReceive('updateWhere')->once()
            ->with(Mockery::on(fn (array $attributes) => isset($attributes['last_seen_at'])), ['id' => 1]);
        $this->jobRepository->shouldReceive('findPendingForEvent')->once()->with(10, 20)->andReturn($jobs);

        $handler = new GetPendingKioskPrintJobsHandler($this->relayService, $this->jobRepository);

        self::assertSame($jobs, $handler->handle(self::TOKEN_EVENT_A));
    }

    public function test_pending_jobs_reject_a_bad_token(): void
    {
        $this->jobRepository->shouldReceive('findPendingForEvent')->never();
        $this->relayRepository->shouldReceive('updateWhere')->never();

        $this->expectException(InvalidKioskPrintRelayTokenException::class);

        (new GetPendingKioskPrintJobsHandler($this->relayService, $this->jobRepository))
            ->handle(str_repeat('z', 48));
    }

    public function test_ack_rejects_a_bad_token(): void
    {
        $this->jobRepository->shouldReceive('findFirstWhere')->never();
        $this->jobRepository->shouldReceive('updateFromArray')->never();

        $this->expectException(InvalidKioskPrintRelayTokenException::class);

        $this->ack(str_repeat('z', 48), 5, KioskPrintJobStatus::SENT);
    }

    public function test_token_of_event_a_cannot_ack_a_job_of_event_b(): void
    {
        $this->jobRepository->shouldReceive('findFirstWhere')->once()
            ->with(['id' => 99, 'event_id' => 10])
            ->andReturnNull();
        $this->jobRepository->shouldReceive('updateFromArray')->never();

        $this->expectException(ResourceNotFoundException::class);

        $this->ack(self::TOKEN_EVENT_A, 99, KioskPrintJobStatus::SENT);
    }

    public function test_sent_marks_the_job_sent_with_sent_at(): void
    {
        Carbon::setTestNow('2026-10-23 09:00:00');
        $this->givenJob(attempts: 1);

        $this->jobRepository->shouldReceive('updateFromArray')->once()
            ->with(5, [
                'status' => KioskPrintJobStatus::SENT->name,
                'sent_at' => '2026-10-23 09:00:00',
                'last_error' => null,
            ])
            ->andReturn($this->job(KioskPrintJobStatus::SENT, 1));

        $this->ack(self::TOKEN_EVENT_A, 5, KioskPrintJobStatus::SENT);
    }

    public function test_failure_goes_back_to_pending_while_attempts_are_below_three(): void
    {
        $this->givenJob(attempts: 1);

        $this->jobRepository->shouldReceive('updateFromArray')->once()
            ->with(5, [
                'status' => KioskPrintJobStatus::PENDING->name,
                'attempts' => 2,
                'last_error' => 'timed out',
            ])
            ->andReturn($this->job(KioskPrintJobStatus::PENDING, 2));

        $this->ack(self::TOKEN_EVENT_A, 5, KioskPrintJobStatus::FAILED, 'timed out');
    }

    public function test_third_failure_marks_the_job_failed(): void
    {
        $this->givenJob(attempts: 2);

        $this->jobRepository->shouldReceive('updateFromArray')->once()
            ->with(5, [
                'status' => KioskPrintJobStatus::FAILED->name,
                'attempts' => 3,
                'last_error' => 'connection refused',
            ])
            ->andReturn($this->job(KioskPrintJobStatus::FAILED, 3));

        $this->ack(self::TOKEN_EVENT_A, 5, KioskPrintJobStatus::FAILED, 'connection refused');
    }

    public function test_ack_on_an_already_sent_job_changes_nothing(): void
    {
        $job = $this->job(KioskPrintJobStatus::SENT, 0);
        $this->jobRepository->shouldReceive('findFirstWhere')->once()->andReturn($job);
        $this->jobRepository->shouldReceive('updateFromArray')->never();

        self::assertSame($job, $this->ack(self::TOKEN_EVENT_A, 5, KioskPrintJobStatus::FAILED, 'late'));
    }

    public function test_cleanup_deletes_jobs_older_than_48_hours(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');

        $this->jobRepository->shouldReceive('deleteWhere')->once()
            ->with([['created_at', '<', '2026-10-23 12:00:00']])
            ->andReturn(4);

        self::assertSame(4, (new PurgeKioskPrintJobsHandler($this->jobRepository))->handle());
    }

    private function ack(string $token, int $jobId, KioskPrintJobStatus $status, ?string $error = null): KioskPrintJobDomainObject
    {
        return (new AcknowledgeKioskPrintJobHandler($this->relayService, $this->jobRepository))
            ->handle(new AcknowledgeKioskPrintJobDTO(
                relay_token: $token,
                job_id: $jobId,
                status: $status,
                error: $error,
            ));
    }

    private function givenJob(int $attempts): void
    {
        $this->jobRepository->shouldReceive('findFirstWhere')->once()
            ->with(['id' => 5, 'event_id' => 10])
            ->andReturn($this->job(KioskPrintJobStatus::PENDING, $attempts));
    }

    private function job(KioskPrintJobStatus $status, int $attempts): KioskPrintJobDomainObject
    {
        return (new KioskPrintJobDomainObject)
            ->setId(5)
            ->setEventId(10)
            ->setStatus($status->name)
            ->setAttempts($attempts);
    }
}
