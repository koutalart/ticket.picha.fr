<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsLineService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsLineServiceTest extends TestCase
{
    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private OrganizerRepositoryInterface|MockInterface $organizerRepository;

    private SmsSettingsService|MockInterface $settingsService;

    private ConfigRepository $config;

    private SmsLineService $service;

    private OrganizerSmsSettingDomainObject $line;

    private OrganizerSmsSettingDomainObject $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 12:00:00');

        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->settingsService = Mockery::mock(SmsSettingsService::class);
        $this->config = new ConfigRepository(['services' => ['httpsms' => []]]);

        $this->service = new SmsLineService(
            $this->settingsRepository,
            $this->messageRepository,
            $this->organizerRepository,
            $this->settingsService,
            $this->config,
        );

        $this->line = (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setAccountId(10)
            ->setOrganizerId(4)
            ->setApiKey('uk_line')
            ->setFromNumber('+262639000000');

        $this->member = (new OrganizerSmsSettingDomainObject)
            ->setId(2)
            ->setAccountId(20)
            ->setOrganizerId(8)
            ->setSharedLineSettingsId(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_line_with_a_single_organizer_gives_every_sms_to_it(): void
    {
        $this->messageRepository->shouldNotReceive('findLastOutboundOrganizerId');

        $this->assertSame($this->line, $this->service->attributeInbound(collect([$this->line]), '+262639123456'));
    }

    public function test_sms_goes_to_the_organizer_who_wrote_last_within_30_days_by_default(): void
    {
        $this->messageRepository->shouldReceive('findLastOutboundOrganizerId')
            ->once()
            ->with([4, 8], '+262639123456', '2026-09-22 12:00:00')
            ->andReturn(8);

        $this->assertSame($this->member, $this->service->attributeInbound(collect([$this->line, $this->member]), '+262639123456'));
    }

    public function test_line_owner_gets_the_sms_when_it_wrote_last(): void
    {
        $this->messageRepository->shouldReceive('findLastOutboundOrganizerId')->andReturn(4);

        $this->assertSame($this->line, $this->service->attributeInbound(collect([$this->line, $this->member]), '+262639123456'));
    }

    public function test_attribution_window_is_configurable(): void
    {
        $this->config->set('services.httpsms.shared_line_attribution_days', 7);

        $this->messageRepository->shouldReceive('findLastOutboundOrganizerId')
            ->once()
            ->with([4, 8], '+262639123456', '2026-10-15 12:00:00')
            ->andReturn(8);

        $this->service->attributeInbound(collect([$this->line, $this->member]), '+262639123456');
    }

    public function test_sms_is_unattributed_when_nobody_wrote_within_the_window(): void
    {
        $this->messageRepository->shouldReceive('findLastOutboundOrganizerId')->once()->andReturnNull();

        $this->assertNull($this->service->attributeInbound(collect([$this->line, $this->member]), '+262639123456'));
    }

    public function test_organizer_outside_the_line_never_gets_the_sms(): void
    {
        $this->messageRepository->shouldReceive('findLastOutboundOrganizerId')->andReturn(99);

        $this->assertNull($this->service->attributeInbound(collect([$this->line, $this->member]), '+262639123456'));
    }

    public function test_members_lists_the_line_then_its_organizers(): void
    {
        $this->settingsRepository->shouldReceive('findWhere')
            ->with(['shared_line_settings_id' => 1])
            ->andReturn(collect([$this->member]));

        $this->assertSame([$this->line, $this->member], $this->service->members($this->line)->all());
    }

    public function test_member_sends_with_the_shared_line(): void
    {
        $this->settingsService->shouldReceive('findSharedLine')->with($this->member)->andReturn($this->line);

        $this->assertSame($this->line, $this->service->resolveLine($this->member));
        $this->assertSame($this->line, $this->service->resolveLine($this->line));
    }

    public function test_member_of_a_missing_line_cannot_send(): void
    {
        $this->settingsService->shouldReceive('findSharedLine')->andReturnNull();

        $this->expectException(SmsGatewayNotConfiguredException::class);
        $this->service->resolveLine($this->member);
    }

    public function test_attach_points_the_organizer_settings_to_the_line(): void
    {
        $own = (new OrganizerSmsSettingDomainObject)->setId(2)->setAccountId(20)->setOrganizerId(8);

        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['organizer_id' => 4])->andReturn($this->line);
        $this->organizerRepository->shouldReceive('findFirstWhere')->with(['id' => 8])
            ->andReturn((new OrganizerDomainObject)->setId(8)->setAccountId(20));
        $this->settingsService->shouldReceive('getOrCreate')->with(20, 8)->andReturn($own);
        $this->settingsRepository->shouldReceive('countWhere')->with(['shared_line_settings_id' => 2])->andReturn(0);
        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(2, ['shared_line_settings_id' => 1])
            ->andReturn($this->member);

        $this->assertSame($this->member, $this->service->attach(8, 4));
    }

    public function test_attach_refuses_a_line_without_api_key(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->line->setApiKey(null));
        $this->settingsRepository->shouldNotReceive('updateFromArray');

        $this->expectException(SmsSharedLineException::class);
        $this->service->attach(8, 4);
    }

    public function test_attach_refuses_to_chain_shared_lines(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->member->setApiKey('uk')->setFromNumber('+262639000001'));
        $this->settingsRepository->shouldNotReceive('updateFromArray');

        $this->expectException(SmsSharedLineException::class);
        $this->service->attach(9, 8);
    }

    public function test_attach_refuses_an_organizer_whose_line_is_already_shared(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['organizer_id' => 8])
            ->andReturn((new OrganizerSmsSettingDomainObject)->setId(2)->setOrganizerId(8)->setApiKey('uk')->setFromNumber('+262639000001'));
        $this->organizerRepository->shouldReceive('findFirstWhere')->andReturn((new OrganizerDomainObject)->setId(4)->setAccountId(10));
        $this->settingsService->shouldReceive('getOrCreate')->with(10, 4)->andReturn($this->line);
        $this->settingsRepository->shouldReceive('countWhere')->with(['shared_line_settings_id' => 1])->andReturn(1);
        $this->settingsRepository->shouldNotReceive('updateFromArray');

        $this->expectException(SmsSharedLineException::class);
        $this->service->attach(4, 8);
    }

    public function test_attach_refuses_the_same_organizer(): void
    {
        $this->expectException(SmsSharedLineException::class);
        $this->service->attach(4, 4);
    }
}
