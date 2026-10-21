<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Models\Contact;
use HiEvents\Models\ContactLinkVisit;
use HiEvents\Models\Event;
use HiEvents\Models\Organizer;
use HiEvents\Models\QrLink;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Domain\Contact\ContactLinkVisitRecorder;
use HiEvents\Services\Domain\Contact\ContactPersonalLinkService;
use HiEvents\Services\Domain\Contact\DTO\ContactLinkVisitContextDTO;
use HiEvents\Services\Domain\Contact\OrderContactAttributionService;
use HiEvents\Services\Domain\Contact\SmsRecipientContactLinker;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportRecipientDTO;
use HiEvents\Services\Domain\Sms\SmsCampaignImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class ContactPersonalLinkTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private Event $event;

    private ContactPersonalLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event] = $this->createEventWithProduct();
        $this->service = $this->app->make(ContactPersonalLinkService::class);
    }

    public function test_every_contact_gets_its_own_unguessable_code_from_the_database(): void
    {
        $first = $this->contact('fatima@example.test');
        $second = $this->contact('anli@example.test');

        self::assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{10}$/', $first->public_code);
        self::assertNotSame($first->public_code, $second->public_code);
    }

    public function test_code_is_found_case_insensitively_and_only_for_its_organizer(): void
    {
        $contact = $this->contact('fatima@example.test');

        self::assertSame($contact->id, $this->service->findByCode(' '.strtoupper($contact->public_code).' ')?->getId());
        self::assertSame($contact->id, $this->service->findByCode($contact->public_code, $this->event->organizer_id)?->getId());
        self::assertNull($this->service->findByCode($contact->public_code, $this->otherOrganizer()->id));
        self::assertNull($this->service->findByCode('abc'));
        self::assertNull($this->service->findByCode('https://x'));
    }

    public function test_code_of_a_merged_contact_leads_to_the_contact_that_was_kept(): void
    {
        $kept = $this->contact('fatima@example.test');
        $merged = $this->contact('fatima.old@example.test', ['merged_into_contact_id' => $kept->id]);

        self::assertSame($kept->id, $this->service->findByCode($merged->public_code)?->getId());
    }

    public function test_personal_url_replaces_qr_tracking_and_keeps_the_rest(): void
    {
        $contact = $this->service->findByCode($this->contact('fatima@example.test')->public_code);
        $code = $contact->getPublicCode();

        self::assertSame("https://innocent976.yt/?c={$code}", $this->service->getUrl('https://innocent976.yt/', $contact));
        self::assertSame(
            "https://innocent976.yt/event/4/soiree?utm_source=sms&c={$code}#billets",
            $this->service->getUrl('https://innocent976.yt/event/4/soiree?qr=smsaaaa1&qrc=1&utm_source=sms&c=old#billets', $contact),
        );
    }

    public function test_visits_are_recorded_with_first_visit_unique_and_bots_ignored(): void
    {
        $contact = $this->service->findByCode($this->contact('fatima@example.test')->public_code);
        $recorder = $this->app->make(ContactLinkVisitRecorder::class);
        $context = new ContactLinkVisitContextDTO('10.0.0.1', self::BROWSER, 'https://www.facebook.com/x', 'yt');

        self::assertTrue($recorder->record($contact, $context));
        self::assertTrue($recorder->record($contact, $context));
        self::assertFalse($recorder->record($contact, new ContactLinkVisitContextDTO('10.0.0.2', 'Googlebot/2.1 (+http://www.google.com/bot.html)')));

        $visits = ContactLinkVisit::where('contact_id', $contact->getId())->orderBy('id')->get();
        self::assertSame([true, false], $visits->pluck('is_unique')->all());
        self::assertSame('facebook.com', $visits->first()->referrer_host);
        self::assertSame('YT', $visits->first()->country_code);
    }

    public function test_public_endpoint_records_a_visit_and_hides_unknown_codes(): void
    {
        $contact = $this->contact('fatima@example.test');

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->postJson("/public/contact-links/{$contact->public_code}/visits", ['referrer' => null])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        $this->postJson('/public/contact-links/zzzzzzzzzz/visits')->assertNotFound();
        self::assertSame(1, ContactLinkVisit::where('contact_id', $contact->id)->count());
    }

    public function test_order_goes_to_the_c_contact_first_then_to_the_contact_behind_the_qr_link(): void
    {
        $attribution = $this->app->make(OrderContactAttributionService::class);
        $fatima = $this->contact('fatima@example.test');
        $anli = $this->contact('anli@example.test');
        $qrLink = $this->qrLinkDomainObject($this->qrLink('smsaaaa1', ['contact_id' => $anli->id]));
        $organizerId = $this->event->organizer_id;

        self::assertSame($fatima->id, $attribution->resolveContactId($fatima->public_code, $organizerId, $qrLink));
        self::assertSame($anli->id, $attribution->resolveContactId(null, $organizerId, $qrLink));
        self::assertSame($anli->id, $attribution->resolveContactId('inconnu12', $organizerId, $qrLink));
        self::assertNull($attribution->resolveContactId($fatima->public_code, $this->otherOrganizer()->id, null));
        self::assertNull($attribution->resolveContactId(null, $organizerId, null));
    }

    public function test_sms_links_already_sent_are_tied_to_the_recipient_contact(): void
    {
        $existing = $this->contact('zaina@example.test', ['phone' => '+262639222222']);
        $this->qrLink('smsaaaa1');
        $this->qrLink('smsbbbb2');
        $this->qrLink('smscccc3');
        $this->qrLink('affiche1');
        $this->importCampaign('Campagne 1', [
            new SmsCampaignImportRecipientDTO(phone: '0639111111', contactName: 'Fatima Abdallah', shortId: 'smsaaaa1'),
            new SmsCampaignImportRecipientDTO(phone: '+262 639 22 22 22', contactName: 'Zaina', shortId: 'smsbbbb2'),
        ]);
        $this->importCampaign('Campagne 2', [
            new SmsCampaignImportRecipientDTO(phone: '+262639111111', contactName: 'Fatima', shortId: 'smscccc3'),
        ]);

        $result = $this->app->make(SmsRecipientContactLinker::class)->link($this->event->organizer_id);

        self::assertSame(3, $result->linkedQrLinks);
        self::assertSame(1, $result->createdContacts);

        $fatima = Contact::where('phone', '+262639111111')->firstOrFail();
        self::assertSame('SMS_CAMPAIGN', $fatima->source);
        self::assertSame('Fatima', $fatima->first_name);
        self::assertSame('Abdallah', $fatima->last_name);
        self::assertNull($fatima->sms_consent_at);
        self::assertSame($fatima->id, QrLink::where('short_id', 'smsaaaa1')->value('contact_id'));
        self::assertSame($fatima->id, QrLink::where('short_id', 'smscccc3')->value('contact_id'));
        self::assertSame($existing->id, QrLink::where('short_id', 'smsbbbb2')->value('contact_id'));
        self::assertNull(QrLink::where('short_id', 'affiche1')->value('contact_id'));

        $again = $this->app->make(SmsRecipientContactLinker::class)->link();
        self::assertSame(0, $again->linkedQrLinks);
        self::assertSame(1, Contact::where('phone', '+262639111111')->count());
    }

    private function contact(string $email, array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'organizer_id' => $this->event->organizer_id,
            'email' => $email,
            'source' => 'MANUAL',
        ], $attributes))->refresh();
    }

    private function otherOrganizer(): Organizer
    {
        return Organizer::create([
            'account_id' => $this->event->account_id,
            'name' => 'Other Organizer',
            'email' => 'other@example.test',
            'currency' => 'EUR',
            'timezone' => 'UTC',
        ]);
    }

    private function qrLink(string $shortId, array $attributes = []): QrLink
    {
        return QrLink::create(array_merge([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'short_id' => $shortId,
            'name' => $shortId,
            'destination_type' => 'EVENT',
            'status' => 'ACTIVE',
        ], $attributes));
    }

    private function qrLinkDomainObject(QrLink $qrLink): QrLinkDomainObject
    {
        return $this->app->make(QrLinkRepositoryInterface::class)->findFirst($qrLink->id);
    }

    /**
     * @param  SmsCampaignImportRecipientDTO[]  $recipients
     */
    private function importCampaign(string $name, array $recipients): void
    {
        $this->app->make(SmsCampaignImportService::class)->import(new SmsCampaignImportDTO(
            organizerId: $this->event->organizer_id,
            name: $name,
            recipients: $recipients,
            eventId: $this->event->id,
        ));
    }
}
