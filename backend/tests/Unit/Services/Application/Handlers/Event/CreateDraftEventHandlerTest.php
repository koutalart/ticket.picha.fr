<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CreateDraftEventHandler;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\CreateDraftEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\DraftQuestionDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\DraftTicketDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Tests\TestCase;

class CreateDraftEventHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_creates_a_draft_with_buyer_paid_fees_tickets_and_order_questions(): void
    {
        $createEvent = Mockery::mock(CreateEventHandler::class);
        $createProduct = Mockery::mock(CreateProductHandler::class);
        $createQuestion = Mockery::mock(CreateQuestionHandler::class);
        $categories = Mockery::mock(ProductCategoryRepositoryInterface::class);
        $settings = Mockery::mock(EventSettingsRepositoryInterface::class);
        $db = Mockery::mock(DatabaseManager::class);
        $db->shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());

        $event = (new EventDomainObject)->setId(25)->setStatus(EventStatus::DRAFT->name);

        $createEvent->shouldReceive('handle')->once()
            ->withArgs(fn (CreateEventDTO $dto) => $dto->status === EventStatus::DRAFT->name
                && $dto->timezone === 'Indian/Mayotte'
                && $dto->organizer_id === 4 && $dto->account_id === 3 && $dto->user_id === 11
                && $dto->start_date === '2026-12-24 20:00:00'
                && $dto->location_details->venue_name === 'Pôle culturel')
            ->andReturn($event);

        $settings->shouldReceive('updateWhere')->once()
            ->with(['pass_platform_fee_to_buyer' => true], ['event_id' => 25]);

        $categories->shouldReceive('findFirstWhere')->once()->with(['event_id' => 25])
            ->andReturn((new ProductCategoryDomainObject)->setId(5));

        $createProduct->shouldReceive('handle')->once()
            ->withArgs(fn (UpsertProductDTO $dto) => $dto->title === 'Prévente' && $dto->type === ProductPriceType::PAID
                && $dto->product_type === ProductType::TICKET && $dto->product_category_id === 5 && $dto->order === 1
                && $dto->prices->first()->price === 15.0 && $dto->prices->first()->initial_quantity_available === 200)
            ->andReturn((new ProductDomainObject)->setId(80));
        $createProduct->shouldReceive('handle')->once()
            ->withArgs(fn (UpsertProductDTO $dto) => $dto->title === 'Invitation' && $dto->type === ProductPriceType::FREE
                && $dto->order === 2 && $dto->prices->first()->price === 0.0 && $dto->prices->first()->initial_quantity_available === null)
            ->andReturn((new ProductDomainObject)->setId(81));

        $createQuestion->shouldReceive('handle')->once()
            ->withArgs(fn (UpsertQuestionDTO $dto) => $dto->event_id === 25 && $dto->belongs_to === QuestionBelongsTo::ORDER
                && $dto->type === QuestionTypeEnum::RADIO && $dto->options === ['Viande', 'Poisson'] && $dto->required
                && ! $dto->is_hidden && $dto->product_ids === [])
            ->andReturn((new QuestionDomainObject)->setId(90));

        $handler = new CreateDraftEventHandler($createEvent, $createProduct, $createQuestion, $categories, $settings, $db);

        $result = $handler->handle(new CreateDraftEventDTO(
            organizer_id: 4,
            account_id: 3,
            user_id: 11,
            title: 'Soirée Gala',
            start_date: '2026-12-24 20:00:00',
            timezone: 'Indian/Mayotte',
            tickets: [
                new DraftTicketDTO(title: 'Prévente', price: 15, quantity: 200),
                new DraftTicketDTO(title: 'Invitation', price: 0),
            ],
            questions: [
                new DraftQuestionDTO(title: 'Repas', type: QuestionTypeEnum::RADIO, required: true, options: ['Viande', 'Poisson']),
            ],
            venue_name: 'Pôle culturel',
        ));

        $this->assertSame($event, $result->event);
        $this->assertSame([80, 81], $result->ticket_ids);
        $this->assertSame([90], $result->question_ids);
    }
}
