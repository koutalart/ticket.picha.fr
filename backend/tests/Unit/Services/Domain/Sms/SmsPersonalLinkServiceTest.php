<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Services\Domain\Contact\ContactPersonalLinkService;
use HiEvents\Services\Domain\Contact\SmsContactResolver;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use HiEvents\Services\Domain\Sms\SmsPersonalLinkService;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class SmsPersonalLinkServiceTest extends TestCase
{
    use FakesPublicBaseUrl;

    private SmsCampaignRecipientRepositoryInterface|MockInterface $recipients;

    private QrLinkRepositoryInterface|MockInterface $qrLinks;

    private QrLinkUrlService|MockInterface $urls;

    private ContactRepositoryInterface|MockInterface $contacts;

    private PublicBaseUrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipients = Mockery::mock(SmsCampaignRecipientRepositoryInterface::class);
        $this->qrLinks = Mockery::mock(QrLinkRepositoryInterface::class);
        $this->urls = Mockery::mock(QrLinkUrlService::class);
        $this->contacts = Mockery::mock(ContactRepositoryInterface::class);
        config(['app.frontend_url' => 'https://ticket.picha.fr']);
        $this->resolver = $this->fakePublicBaseUrl([8 => 'mayottela1ere.fr']);
    }

    public function test_contact_gets_their_c_link_on_the_organizer_site(): void
    {
        $this->expectContact('+262639017818');
        $this->resolver = $this->fakePublicBaseUrl([4 => 'innocent976.yt', 8 => 'mayottela1ere.fr']);

        $link = $this->service()->findForContact(4, '+262639017818', collect([
            $this->outbound('https://innocent976.yt/?qr=iiekn0hg'),
        ]));

        self::assertSame('https://innocent976.yt/?c=k7mzq2xwpa', $link);
    }

    public function test_without_custom_domain_the_c_link_reuses_the_last_tracked_link_sent(): void
    {
        $this->expectContact('+262639017818');

        $link = $this->service()->findForContact(4, '0639 01 78 18', collect([
            $this->outbound('Ta place : https://innocent976.yt/event/7/halloween?qr=iiekn0hg&qrc=1.'),
            $this->outbound('Redirection : https://ticket.picha.fr/q/iiekn0hg'),
        ]));

        self::assertSame('https://innocent976.yt/event/7/halloween?c=k7mzq2xwpa', $link);
    }

    public function test_qr_link_stays_the_fallback_when_the_c_link_has_no_site(): void
    {
        $this->expectContact('+262639017818');
        $qrLink = $this->expectCampaignQrLink('iiekn0hg');
        $this->urls->shouldReceive('getPublicUrl')->with($qrLink)->andReturn('https://ticket.picha.fr/q/iiekn0hg');

        $link = $this->service()->findForContact(4, '+262639017818', collect([
            $this->outbound('https://ticket.picha.fr/q/iiekn0hg'),
        ]));

        self::assertSame('https://ticket.picha.fr/q/iiekn0hg', $link);
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

    public function test_platform_link_sent_before_the_custom_domain_is_replaced_by_the_organizer_domain(): void
    {
        $qrLink = $this->expectCampaignQrLink('k4rm1ere', organizerId: 8);
        $this->urls->shouldReceive('getPublicUrl')->with($qrLink)->andReturn('https://mayottela1ere.fr/q/k4rm1ere');

        $link = $this->service()->findForContact(8, '+262639017818', collect([
            $this->outbound('Ta place : https://ticket.picha.fr/q/k4rm1ere?qr=k4rm1ere'),
        ]));

        self::assertSame('https://mayottela1ere.fr/q/k4rm1ere', $link);
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
        self::assertSame(
            'Ici : https://innocent976.yt/?c=k7mzq2xwpa',
            SmsPersonalLinkService::applyToReply('Ici : https://innocent976.yt/?c=k7mzq2xwpa', 'https://innocent976.yt/?c=other12345'),
        );
        self::assertSame('Ici : https://innocent976.yt', SmsPersonalLinkService::applyToReply('Ici : https://innocent976.yt', null));
    }

    private function expectCampaignQrLink(string $shortId, int $organizerId = 4): QrLinkDomainObject
    {
        $qrLink = (new QrLinkDomainObject)->setId(12)->setOrganizerId($organizerId)->setShortId($shortId);

        $this->recipients->shouldReceive('findWhere')->andReturn(collect([
            (new SmsCampaignRecipientDomainObject)->setId(3)->setQrLinkId(12),
        ]));
        $this->qrLinks->shouldReceive('findWhereIn')->with('id', [12], ['organizer_id' => $organizerId])->andReturn(collect([$qrLink]));

        return $qrLink;
    }

    private function expectContact(string $phone): void
    {
        $this->contacts->shouldReceive('findFirstWhere')
            ->with(Mockery::on(fn (array $where) => $where['organizer_id'] === 4 && $where['phone'] === $phone))
            ->andReturn((new ContactDomainObject)->setId(31)->setOrganizerId(4)->setPhone($phone)->setPublicCode('k7mzq2xwpa'));
    }

    private function service(): SmsPersonalLinkService
    {
        $this->contacts->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();

        return new SmsPersonalLinkService(
            $this->recipients,
            $this->qrLinks,
            $this->urls,
            $this->resolver,
            new SmsContactResolver($this->contacts),
            new ContactPersonalLinkService($this->contacts, $this->resolver),
        );
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
