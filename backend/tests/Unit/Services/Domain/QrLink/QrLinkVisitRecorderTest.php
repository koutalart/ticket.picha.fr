<?php

namespace Tests\Unit\Services\Domain\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkVisitType;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\QrLinkVisitDomainObject;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkVisitRepositoryInterface;
use HiEvents\Services\Domain\QrLink\DTO\QrLinkVisitContextDTO;
use HiEvents\Services\Domain\QrLink\QrLinkVisitRecorder;
use HiEvents\Services\Domain\QrLink\UserAgentParser;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class QrLinkVisitRecorderTest extends TestCase
{
    private const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private QrLinkVisitRepositoryInterface|MockInterface $visitRepository;

    private QrLinkRepositoryInterface|MockInterface $qrLinkRepository;

    private QrLinkVisitRecorder $recorder;

    private QrLinkDomainObject $qrLink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->visitRepository = Mockery::mock(QrLinkVisitRepositoryInterface::class);
        $this->qrLinkRepository = Mockery::mock(QrLinkRepositoryInterface::class);
        $this->recorder = new QrLinkVisitRecorder($this->visitRepository, $this->qrLinkRepository, new UserAgentParser);
        $this->qrLink = (new QrLinkDomainObject)->setId(12);
    }

    public function test_bots_are_not_recorded(): void
    {
        $this->visitRepository->shouldNotReceive('create');
        $this->qrLinkRepository->shouldNotReceive('incrementVisitCounters');

        $recorded = $this->recorder->record($this->qrLink, new QrLinkVisitContextDTO(
            type: QrLinkVisitType::SCAN,
            ipAddress: '1.2.3.4',
            userAgent: 'WhatsApp/2.23.20.0',
        ));

        $this->assertFalse($recorded);
    }

    public function test_first_scan_from_visitor_is_unique(): void
    {
        $this->visitRepository->shouldReceive('hasScanFromVisitor')->once()->andReturnFalse();
        $this->visitRepository->shouldReceive('create')->once()->withArgs(function (array $attributes) {
            return $attributes['qr_link_id'] === 12
                && $attributes['type'] === 'SCAN'
                && $attributes['is_unique'] === true
                && $attributes['device_type'] === 'mobile'
                && $attributes['os'] === 'iOS'
                && $attributes['country_code'] === 'YT'
                && $attributes['referrer_host'] === 'instagram.com'
                && strlen($attributes['visitor_hash']) === 64
                && ! str_contains($attributes['visitor_hash'], '1.2.3.4');
        })->andReturn(new QrLinkVisitDomainObject);
        $this->qrLinkRepository->shouldReceive('incrementVisitCounters')->once()->with(12, true, true);

        $this->assertTrue($this->recorder->record($this->qrLink, new QrLinkVisitContextDTO(
            type: QrLinkVisitType::SCAN,
            ipAddress: '1.2.3.4',
            userAgent: self::IPHONE_UA,
            referrer: 'https://www.instagram.com/p/abc',
            countryCode: 'yt',
        )));
    }

    public function test_repeat_scan_is_not_unique(): void
    {
        $this->visitRepository->shouldReceive('hasScanFromVisitor')->once()->andReturnTrue();
        $this->visitRepository->shouldReceive('create')->once()
            ->withArgs(fn (array $attributes) => $attributes['is_unique'] === false && $attributes['country_code'] === null)
            ->andReturn(new QrLinkVisitDomainObject);
        $this->qrLinkRepository->shouldReceive('incrementVisitCounters')->once()->with(12, true, false);

        $this->recorder->record($this->qrLink, new QrLinkVisitContextDTO(
            type: QrLinkVisitType::SCAN,
            ipAddress: '1.2.3.4',
            userAgent: self::IPHONE_UA,
            countryCode: 'XX',
        ));
    }

    public function test_click_stores_normalized_target(): void
    {
        $this->visitRepository->shouldNotReceive('hasScanFromVisitor');
        $this->visitRepository->shouldReceive('create')->once()
            ->withArgs(fn (array $attributes) => $attributes['type'] === 'CLICK'
                && $attributes['target'] === 'instagram'
                && $attributes['is_unique'] === false)
            ->andReturn(new QrLinkVisitDomainObject);
        $this->qrLinkRepository->shouldReceive('incrementVisitCounters')->once()->with(12, false, false);

        $this->recorder->record($this->qrLink, new QrLinkVisitContextDTO(
            type: QrLinkVisitType::CLICK,
            ipAddress: '1.2.3.4',
            userAgent: self::IPHONE_UA,
            target: 'Insta<gram>',
        ));
    }

    public function test_visitor_hash_differs_per_qr_link(): void
    {
        $hashes = [];
        $this->visitRepository->shouldReceive('hasScanFromVisitor')->andReturnFalse();
        $this->visitRepository->shouldReceive('create')->twice()->withArgs(function (array $attributes) use (&$hashes) {
            $hashes[] = $attributes['visitor_hash'];

            return true;
        })->andReturn(new QrLinkVisitDomainObject);
        $this->qrLinkRepository->shouldReceive('incrementVisitCounters')->twice();

        $context = new QrLinkVisitContextDTO(type: QrLinkVisitType::SCAN, ipAddress: '1.2.3.4', userAgent: self::IPHONE_UA);
        $this->recorder->record($this->qrLink, $context);
        $this->recorder->record((new QrLinkDomainObject)->setId(13), $context);

        $this->assertNotSame($hashes[0], $hashes[1]);
    }
}
