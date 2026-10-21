<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\KioskPrintJobDomainObject;
use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\KioskPrintJobRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\EnqueueKioskPrintJobsDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\EnqueueKioskPrintJobsHandler;
use HiEvents\Services\Domain\BoxOffice\BoxOfficeTicketZplService;
use HiEvents\Services\Domain\BoxOffice\DTO\BoxOfficeTicketZplDTO;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EnqueueKioskPrintJobsHandlerTest extends TestCase
{
    private KioskPrintJobRepositoryInterface&MockInterface $jobRepository;

    private BoxOfficeTicketZplService&MockInterface $zplService;

    private EnqueueKioskPrintJobsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobRepository = Mockery::mock(KioskPrintJobRepositoryInterface::class);
        $this->zplService = Mockery::mock(BoxOfficeTicketZplService::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->handler = new EnqueueKioskPrintJobsHandler($this->jobRepository, $this->zplService, $databaseManager);
    }

    public function test_creates_one_pending_job_per_attendee_with_the_service_zpl(): void
    {
        $labelFormat = new ZplLabelFormatDTO(dpi: 300, width_mm: 80.0, length_mm: 101.0);

        $this->zplService->shouldReceive('build')->once()->with(7, 'A-ONE', $labelFormat)
            ->andReturn(new BoxOfficeTicketZplDTO(attendee_id: 11, zpl: '^XA^FDone^FS^XZ'));
        $this->zplService->shouldReceive('build')->once()->with(7, 'A-TWO', $labelFormat)
            ->andReturn(new BoxOfficeTicketZplDTO(attendee_id: 12, zpl: '^XA^FDtwo^FS^XZ'));
        $this->zplService->shouldReceive('recordPrintJob')->once()->with(11, 3);
        $this->zplService->shouldReceive('recordPrintJob')->once()->with(12, 3);

        $created = [];
        $this->jobRepository->shouldReceive('create')->twice()->andReturnUsing(function (array $attributes) use (&$created) {
            $created[] = $attributes;

            return (new KioskPrintJobDomainObject)->setId(100 + count($created));
        });

        $jobIds = $this->handler->handle(new EnqueueKioskPrintJobsDTO(
            event_id: 7,
            attendee_public_ids: ['A-ONE', 'A-TWO'],
            user_id: 3,
            label_format: $labelFormat,
        ));

        self::assertSame([101, 102], $jobIds);
        self::assertSame('A-ONE', $created[0]['attendee_public_id']);
        self::assertSame('^XA^FDone^FS^XZ', $created[0]['zpl']);
        self::assertSame('^XA^FDtwo^FS^XZ', $created[1]['zpl']);
        foreach ($created as $attributes) {
            self::assertSame(7, $attributes['event_id']);
            self::assertSame(KioskPrintJobStatus::PENDING->name, $attributes['status']);
            self::assertSame(0, $attributes['attempts']);
            self::assertSame(3, $attributes['created_by_user_id']);
        }
    }

    public function test_queues_nothing_when_one_attendee_is_not_in_the_event(): void
    {
        $this->zplService->shouldReceive('build')->with(7, 'A-ONE', null)
            ->andReturn(new BoxOfficeTicketZplDTO(attendee_id: 11, zpl: '^XA^XZ'));
        $this->zplService->shouldReceive('build')->with(7, 'OTHER-EVENT', null)
            ->andThrow(new ResourceNotFoundException('Attendee not found.'));
        $this->jobRepository->shouldReceive('create')->never();
        $this->zplService->shouldReceive('recordPrintJob')->never();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(new EnqueueKioskPrintJobsDTO(
            event_id: 7,
            attendee_public_ids: ['A-ONE', 'OTHER-EVENT'],
            user_id: 3,
        ));
    }
}
