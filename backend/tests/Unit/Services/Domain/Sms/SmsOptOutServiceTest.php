<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsOptOutService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsOptOutServiceTest extends TestCase
{
    private SmsOptOutRepositoryInterface|MockInterface $repository;

    private SmsOptOutService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(SmsOptOutRepositoryInterface::class);
        $this->service = new SmsOptOutService($this->repository);
    }

    public function test_manual_opt_out_normalizes_the_number(): void
    {
        $this->repository->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => 4, 'phone' => '+262639123456'])
            ->andReturnNull();
        $this->repository->shouldReceive('create')
            ->once()
            ->with(['organizer_id' => 4, 'phone' => '+262639123456', 'source' => 'MANUAL'])
            ->andReturn((new SmsOptOutDomainObject)->setId(1));

        $this->assertSame(1, $this->service->addManual(4, '0639 12 34 56')->getId());
    }

    public function test_existing_opt_out_is_returned(): void
    {
        $existing = (new SmsOptOutDomainObject)->setId(2)->setSource('KEYWORD');
        $this->repository->shouldReceive('findFirstWhere')->andReturn($existing);
        $this->repository->shouldNotReceive('create');

        $this->assertSame($existing, $this->service->addManual(4, '+262639123456'));
    }

    public function test_invalid_number_is_rejected(): void
    {
        $this->repository->shouldNotReceive('create');

        $this->expectException(InvalidSmsPhoneNumberException::class);

        $this->service->addManual(4, 'abc');
    }

    public function test_remove_is_scoped_to_the_organizer(): void
    {
        $this->repository->shouldReceive('findFirstWhere')->with(['id' => 3, 'organizer_id' => 4])->andReturnNull();
        $this->repository->shouldNotReceive('deleteWhere');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->remove(4, 3);
    }

    public function test_remove_deletes_the_opt_out(): void
    {
        $where = ['id' => 3, 'organizer_id' => 4];
        $this->repository->shouldReceive('findFirstWhere')->with($where)->andReturn(new SmsOptOutDomainObject);
        $this->repository->shouldReceive('deleteWhere')->once()->with($where);

        $this->service->remove(4, 3);
    }
}
