<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\CreatedDraftEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\CreateDraftEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\DraftQuestionDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\DraftTicketDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Creates an event that nobody can see yet (always DRAFT), with its tickets and its order form.
 * The platform fee is always added to the buyer's price.
 */
class CreateDraftEventHandler
{
    public function __construct(
        private readonly CreateEventHandler $createEventHandler,
        private readonly CreateProductHandler $createProductHandler,
        private readonly CreateQuestionHandler $createQuestionHandler,
        private readonly ProductCategoryRepositoryInterface $productCategoryRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(CreateDraftEventDTO $data): CreatedDraftEventDTO
    {
        return $this->databaseManager->transaction(function () use ($data) {
            $event = $this->createEventHandler->handle(new CreateEventDTO(
                title: $data->title,
                organizer_id: $data->organizer_id,
                account_id: $data->account_id,
                user_id: $data->user_id,
                start_date: $data->start_date,
                end_date: $data->end_date,
                description: $data->description,
                timezone: $data->timezone,
                location_details: $data->venue_name !== null ? new AddressDTO(venue_name: $data->venue_name) : null,
                status: EventStatus::DRAFT->name,
            ));

            $this->eventSettingsRepository->updateWhere(
                attributes: ['pass_platform_fee_to_buyer' => true],
                where: ['event_id' => $event->getId()],
            );

            $categoryId = $this->defaultCategoryId($event);
            $ticketIds = [];

            foreach (array_values($data->tickets) as $index => $ticket) {
                $ticketIds[] = $this->createTicket($event, $data->account_id, $categoryId, $ticket, $index + 1);
            }

            $questionIds = array_map(
                fn (DraftQuestionDTO $question) => $this->createQuestion($event, $question),
                $data->questions,
            );

            return new CreatedDraftEventDTO(
                event: $event,
                ticket_ids: $ticketIds,
                question_ids: array_values($questionIds),
            );
        });
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function defaultCategoryId(EventDomainObject $event): int
    {
        /** @var ProductCategoryDomainObject|null $category */
        $category = $this->productCategoryRepository->findFirstWhere(['event_id' => $event->getId()]);

        if ($category === null) {
            throw new ResourceNotFoundException(__('The event has no ticket category.'));
        }

        return $category->getId();
    }

    /**
     * @throws Throwable
     */
    private function createTicket(EventDomainObject $event, int $accountId, int $categoryId, DraftTicketDTO $ticket, int $order): int
    {
        $type = $ticket->isFree() ? ProductPriceType::FREE : ProductPriceType::PAID;

        return $this->createProductHandler->handle(new UpsertProductDTO(
            account_id: $accountId,
            event_id: $event->getId(),
            product_category_id: $categoryId,
            title: $ticket->title,
            type: $type,
            product_type: ProductType::TICKET,
            prices: collect([new ProductPriceDTO(
                price: $ticket->isFree() ? 0.00 : $ticket->price,
                initial_quantity_available: $ticket->quantity,
            )]),
            price: $ticket->isFree() ? 0.00 : $ticket->price,
            order: $order,
            initial_quantity_available: $ticket->quantity,
        ))->getId();
    }

    /**
     * @throws Throwable
     */
    private function createQuestion(EventDomainObject $event, DraftQuestionDTO $question): int
    {
        return $this->createQuestionHandler->handle(new UpsertQuestionDTO(
            title: $question->title,
            type: $question->type,
            required: $question->required,
            options: $question->options,
            event_id: $event->getId(),
            product_ids: [],
            is_hidden: false,
            belongs_to: QuestionBelongsTo::ORDER,
            description: $question->description,
        ))->getId();
    }
}
