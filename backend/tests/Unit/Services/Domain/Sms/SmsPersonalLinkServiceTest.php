<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use HiEvents\Services\Domain\Sms\SmsPersonalLinkService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsPersonalLinkServiceTest extends TestCase
{
    private SmsCampaignRecipientRepositoryInterface|MockInterface $recipients;

    private QrLinkRepositoryInterface|MockInterface $qrLinks;

    private QrLinkUrlService|MockInterface $urls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipients = Mockery::mock(SmsCampaignRecipientRepositoryInterface::class);
        $this->qrLinks = Mockery::mock(QrLinkRepositoryInterface::class);
        $this->urls = Mockery::mock(QrLinkUrlService::class);
    }

    public function test_link_already_sent_in_the_campaign_is_reused_as_is(): void
    {
        $this->expectCampaignQrLink('iiekn0hg');

        $link = $this->service()->findForContact(4, '+262639017818', collect([
            $this->outbound('Heeyy Volatsara ! Halloween le 31 au 5/5.'),
            $this->outbound('https://innocent976.yt/?qr=iiekn0hg'),
            $this->inbound('Et comment faire pour avoir les billets ?'),
        ]));

        self::assertSame('https://innocent976.yt/?qr=iiekn0hg', $link);
    }

    public function test_tracked_qr_link_is_used_when_the_link_was_never_sent(): void
    {
        $qrLink = $this->expectCampaignQrLink('aq7d407g');
        $this->urls->shouldReceive('getPublicUrl')->with($qrLink)->andReturn('https://ticket.picha.fr/q/aq7d407g');

        $link = $this->service()->findForContact(4, '+262639091486', collect([$this->inbound('Merci')]));

        self::assertSame('https://ticket.picha.fr/q/aq7d407g', $link);
    }

    public function test_last_tracked_link_of_the_thread_is_used_without_campaign(): void
    {
        $this->recipients->shouldReceive('findWhere')->andReturn(collect());

        $link = $this->service()->findForContact(4, '+262639000000', collect([
            $this->outbound('Ta place : https://innocent976.yt/?qr=old1.'),
            $this->outbound('Voici la vidéo : https://we.tl/t-abc'),
        ]));

        self::assertSame('https://innocent976.yt/?qr=old1', $link);
    }

    public function test_no_link_without_campaign_nor_tracked_link(): void
    {
        $this->recipients->shouldReceive('findWhere')->andReturn(collect());

        self::assertNull($this->service()->findForContact(4, '+262639000000', collect([$this->inbound('Salut')])));
    }

    public function test_plain_link_to_the_same_site_is_replaced_in_the_reply(): void
    {
        $personal = 'https://innocent976.yt/?qr=iiekn0hg';

        self::assertSame(
            'Si si, il en reste ! Ta place est ici : https://innocent976.yt/?qr=iiekn0hg 🎃',
            SmsPersonalLinkService::applyToReply('Si si, il en reste ! Ta place est ici : https://innocent976.yt 🎃', $personal),
        );
        self::assertSame(
            'Ici : https://innocent976.yt/?qr=iiekn0hg.',
            SmsPersonalLinkService::applyToReply('Ici : https://www.innocent976.yt/.', $personal),
        );
        self::assertSame('Vidéo : https://we.tl/t-abc', SmsPersonalLinkService::applyToReply('Vidéo : https://we.tl/t-abc', $personal));
        self::assertSame('Ici : https://innocent976.yt', SmsPersonalLinkService::applyToReply('Ici : https://innocent976.yt', null));
    }

    private function expectCampaignQrLink(string $shortId): QrLinkDomainObject
    {
        $qrLink = (new QrLinkDomainObject)->setId(12)->setOrganizerId(4)->setShortId($shortId);

        $this->recipients->shouldReceive('findWhere')->andReturn(collect([
            (new SmsCampaignRecipientDomainObject)->setId(3)->setQrLinkId(12),
        ]));
        $this->qrLinks->shouldReceive('findWhereIn')->with('id', [12], ['organizer_id' => 4])->andReturn(collect([$qrLink]));

        return $qrLink;
    }

    private function service(): SmsPersonalLinkService
    {
        return new SmsPersonalLinkService($this->recipients, $this->qrLinks, $this->urls);
    }

    private function outbound(string $content): SmsMessageDomainObject
    {
        return (new SmsMessageDomainObject)->setDirection('OUTBOUND')->setContent($content);
    }

    private function inbound(string $content): SmsMessageDomainObject
    {
        return (new SmsMessageDomainObject)->setDirection('INBOUND')->setContent($content);
    }
}
