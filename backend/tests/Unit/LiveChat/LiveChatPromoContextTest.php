<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsAssistantCallRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatReplyDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatRequestDTO;
use HiEvents\Services\Domain\LiveChat\LiveChatPromoContextBuilder;
use HiEvents\Services\Domain\LiveChat\LiveChatReplyGenerator;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantTicketInfoDTO;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use Illuminate\Config\Repository;
use Mockery;
use Psr\Log\NullLogger;
use Tests\TestCase;

class LiveChatPromoContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_valid_highlighted_code_is_given_with_its_real_conditions(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $promo = (new PromoCodeDomainObject)
            ->setId(3)
            ->setEventId(7)
            ->setCode('djinn5')
            ->setDiscount(5)
            ->setDiscountType('FIXED')
            ->setApplicableProductIds([11])
            ->setExpiryDate('2026-10-31 16:00:00')
            ->setMaxAllowedUsages(100)
            ->setOrderUsageCount(12);

        $promos = $this->builder($promo, products: [(new ProductDomainObject)->setId(11)->setTitle('Prévente')])->forEvents([7]);

        $this->assertSame([7 => [
            'code' => 'djinn5',
            'reduction' => '5 euros de réduction sur le prix de chaque billet concerné',
            'billets_concernes' => ['Prévente'],
            'fin' => 'samedi 31 octobre 2026 à 19h00',
        ]], $promos);
    }

    public function test_a_code_without_products_applies_to_every_ticket(): void
    {
        $promo = (new PromoCodeDomainObject)->setEventId(7)->setCode('halloween')->setDiscount(10)->setDiscountType('PERCENTAGE');

        $promos = $this->builder($promo)->forEvents([7]);

        $this->assertSame(LiveChatPromoContextBuilder::ALL_PRODUCTS, $promos[7]['billets_concernes']);
        $this->assertSame('10 % de réduction sur le prix de chaque billet concerné', $promos[7]['reduction']);
        $this->assertSame('pas de date de fin', $promos[7]['fin']);
    }

    public function test_an_expired_code_is_not_given(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $promo = (new PromoCodeDomainObject)->setEventId(7)->setCode('djinn5')->setDiscount(5)->setDiscountType('FIXED')
            ->setExpiryDate('2026-10-28 20:00:00');

        $this->assertSame([], $this->builder($promo)->forEvents([7]));
    }

    public function test_an_exhausted_code_is_not_given(): void
    {
        $promo = (new PromoCodeDomainObject)->setEventId(7)->setCode('djinn5')->setDiscount(5)->setDiscountType('FIXED')
            ->setMaxAllowedUsages(50)->setOrderUsageCount(50);

        $this->assertSame([], $this->builder($promo)->forEvents([7]));
    }

    public function test_a_missing_or_deleted_code_is_not_given(): void
    {
        $this->assertSame([], $this->builder(null)->forEvents([7]));
    }

    public function test_a_code_without_discount_is_not_given(): void
    {
        $promo = (new PromoCodeDomainObject)->setEventId(7)->setCode('acces')->setDiscount(0)->setDiscountType('NONE');

        $this->assertSame([], $this->builder($promo)->forEvents([7]));
    }

    public function test_no_highlighted_code_means_no_lookup(): void
    {
        $settings = Mockery::mock(EventSettingsRepositoryInterface::class);
        $settings->shouldReceive('findWhereIn')->andReturn(collect([(new EventSettingDomainObject)->setEventId(7)->setHighlightedPromoCode(null)]));
        $promoCodes = Mockery::mock(PromoCodeRepositoryInterface::class);
        $promoCodes->shouldNotReceive('findFirstWhere');

        $builder = new LiveChatPromoContextBuilder(
            $settings,
            $promoCodes,
            Mockery::mock(ProductRepositoryInterface::class),
            Mockery::mock(EventRepositoryInterface::class),
        );

        $this->assertSame([], $builder->forEvents([7]));
    }

    public function test_the_prompt_pushes_to_book_and_gives_the_code_or_says_there_is_none(): void
    {
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('isConfigured')->andReturn(true);
        $claude->shouldReceive('askWithUsage')
            ->once()
            ->withArgs(function (string $system, array $content) {
                $text = $content[0]['text'];

                return str_contains($system, "D'abord le prix")
                    && str_contains($system, 'bouton Réserver')
                    && str_contains($system, "N'invente jamais de code")
                    && str_contains($system, 'ne demande jamais de coordonnées pour une question à laquelle tu as répondu (prix')
                    && str_contains($text, "Bouton « Réserver » en haut de la fenêtre : oui, pour l'événement de la page consultée")
                    && str_contains($text, '"code_promo_public": {')
                    && str_contains($text, '"code": "djinn5"')
                    && str_contains($text, '"billets_concernes": [')
                    && str_contains($text, '"code_promo_public": "aucun"');
            })
            ->andReturn(new ClaudeStructuredAnswerDTO(
                data: ['messages' => ['15 euros en vente flash, au lieu de 20.', 'Tu peux réserver direct avec le bouton Réserver en haut.'], 'needs_follow_up' => false, 'ask_contact' => false, 'reason' => null, 'intent' => 'none', 'question_for_organizer' => null],
                model: 'claude-haiku-5-5',
                inputTokens: 10,
                outputTokens: 5,
            ));
        $calls = Mockery::mock(SmsAssistantCallRepositoryInterface::class);
        $calls->shouldReceive('countWhere')->andReturn(0);
        $calls->shouldReceive('create');

        $generator = new LiveChatReplyGenerator(
            $claude,
            $calls,
            new Repository(['services' => ['live_chat' => ['daily_limit' => 300, 'model' => 'claude-haiku-5-5']]]),
            new NullLogger,
        );

        $reply = $generator->generate(new LiveChatRequestDTO(
            organizerId: 4,
            sessionId: 1,
            smsConversationId: null,
            organizerName: 'Innocent Event',
            timezone: 'Indian/Mayotte',
            instructions: null,
            tutoiement: true,
            contactKnown: false,
            currentEventId: 7,
            thread: collect([(new LiveChatMessageDomainObject)->setAuthorType('VISITOR')->setContent('c\'est combien ?')]),
            events: collect([
                $this->event(7, 'Halloween'),
                $this->event(8, 'Nouvel an'),
            ]),
            greeting: LiveChatService::greeting(true),
            visitorMessageNumber: 3,
            promoCodes: [7 => [
                'code' => 'djinn5',
                'reduction' => '5 euros de réduction sur le prix de chaque billet concerné',
                'billets_concernes' => ['Prévente'],
                'fin' => 'samedi 31 octobre 2026 à 19h00',
            ]],
        ));

        $this->assertFalse($reply->askContact);
    }

    public function test_the_model_cannot_ask_contact_for_a_question_it_answered(): void
    {
        $answered = new LiveChatReplyDTO(messages: ['15 euros.'], needsFollowUp: false, askContact: true, reason: null);

        $this->assertFalse(LiveChatService::modelMayAskContact($answered, 'c\'est combien la place ?'));
        $this->assertTrue(LiveChatService::modelMayAskContact($answered, 'vous pouvez me rappeler ?'));
        $this->assertTrue(LiveChatService::modelMayAskContact(
            new LiveChatReplyDTO(messages: ['Je transmets.'], needsFollowUp: false, askContact: true, reason: null, questionForOrganizer: 'Parking ?'),
            'il y a un parking ?',
        ));
        $this->assertTrue(LiveChatService::modelMayAskContact(
            new LiveChatReplyDTO(messages: ['Je transmets.'], needsFollowUp: true, askContact: true, reason: 'Litige'),
            'je suis pas content',
        ));
        $this->assertFalse(LiveChatService::modelMayAskContact(
            new LiveChatReplyDTO(messages: ['Je transmets.'], needsFollowUp: true, askContact: false, reason: 'Litige'),
            'je suis pas content',
        ));
    }

    /**
     * @param  list<ProductDomainObject>  $products
     */
    private function builder(?PromoCodeDomainObject $promo, array $products = []): LiveChatPromoContextBuilder
    {
        $settings = Mockery::mock(EventSettingsRepositoryInterface::class);
        $settings->shouldReceive('findWhereIn')
            ->with('event_id', [7])
            ->andReturn(collect([(new EventSettingDomainObject)->setEventId(7)->setHighlightedPromoCode(' DJINN5 ')]));

        $promoCodes = Mockery::mock(PromoCodeRepositoryInterface::class);
        $promoCodes->shouldReceive('findFirstWhere')
            ->with(['event_id' => 7, 'code' => 'djinn5'])
            ->andReturn($promo);

        $productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('findWhereIn')
            ->with('id', [11], ['event_id' => 7])
            ->andReturn(collect($products));

        $events = Mockery::mock(EventRepositoryInterface::class);
        $events->shouldReceive('findFirstWhere')
            ->with(['id' => 7])
            ->andReturn((new EventDomainObject)->setId(7)->setCurrency('EUR')->setTimezone('Indian/Mayotte'));

        return new LiveChatPromoContextBuilder($settings, $promoCodes, $productRepository, $events);
    }

    private function event(int $id, string $title): SmsAssistantEventInfoDTO
    {
        return new SmsAssistantEventInfoDTO(
            title: $title,
            startsAt: '2026-10-31 20:00',
            endsAt: null,
            venue: '5/5',
            url: 'https://innocent976.yt',
            tickets: [new SmsAssistantTicketInfoDTO(name: 'Prévente', price: 15.0, currency: 'EUR', availability: SmsAssistantTicketInfoDTO::ON_SALE)],
            id: $id,
        );
    }
}
