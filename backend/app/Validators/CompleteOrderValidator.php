<?php

declare(strict_types=1);

namespace HiEvents\Validators;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QuestionDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use HiEvents\Validators\Rules\OrderQuestionRule;
use HiEvents\Validators\Rules\ProductQuestionRule;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

class CompleteOrderValidator extends BaseValidator
{
    public function __construct(
        private readonly QuestionRepositoryInterface      $questionRepository,
        private readonly ProductRepositoryInterface       $productRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly OrderRepositoryInterface         $orderRepository,
        private readonly QuestionAnswerFileService        $questionAnswerFileService,
        private readonly Route                            $route
    )
    {
    }

    public function rules(): array
    {
        $questions = $this->questionRepository
            ->loadRelation(
                new Relationship(ProductDomainObject::class, [
                    new Relationship(ProductPriceDomainObject::class)
                ])
            )
            ->findWhere(
                [QuestionDomainObjectAbstract::EVENT_ID => $this->route->parameter('event_id')]
            );

        $orderQuestions = $questions->filter(
            fn(QuestionDomainObject $question) => $question->getBelongsTo() === QuestionBelongsTo::ORDER->name
        );

        $productQuestions = $questions->filter(
            fn(QuestionDomainObject $question) => $question->getBelongsTo() === QuestionBelongsTo::PRODUCT->name
        );

        $products = $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findWhere(
                [ProductDomainObjectAbstract::EVENT_ID => $this->route->parameter('event_id')]
            );

        /** @var EventSettingDomainObject $eventSettings */
        $eventSettings = $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $this->route->parameter('event_id'),
        ]);

        $uploadedFiles = $this->getUploadedFiles();

        $addressRules = $eventSettings->getRequireBillingAddress() ? [
            'order.address' => 'array',
            'order.address.address_line_1' => 'required|string|max:255',
            'order.address.address_line_2' => 'nullable|string|max:255',
            'order.address.city' => 'required|string|max:85',
            'order.address.state_or_region' => 'nullable|string|max:85',
            'order.address.zip_or_postal_code' => 'nullable|string|max:85',
            'order.address.country' => 'required|string|max:2',
        ] : [];

        return [
            'order.first_name' => ['required', 'string', 'max:40'],
            'order.last_name' => ['required', 'string', 'max:40'],
            'order.questions' => (new OrderQuestionRule($orderQuestions, $products))
                ->setUploadedFiles($uploadedFiles),
            'order.email' => 'required|email',
            'order.email_confirmation' => 'required|email|same:order.email',
            'products' => (new ProductQuestionRule(
                $productQuestions,
                $products,
                $eventSettings->getAttendeeDetailsCollectionMethod(),
            ))->setUploadedFiles($uploadedFiles),
            'products.*.birth_date' => ['nullable', 'date_format:Y-m-d'],
            ...$addressRules
        ];
    }

    private function getUploadedFiles(): Collection
    {
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::SHORT_ID => $this->route->parameter('order_short_id'),
            OrderDomainObjectAbstract::EVENT_ID => $this->route->parameter('event_id'),
        ]);

        if ($order === null) {
            return collect();
        }

        return $this->questionAnswerFileService->getUnattachedFilesForOrder($order->getId());
    }

    public function messages(): array
    {
        return [
            'order.first_name.max' => 'First name must be under 40 characters',
            'order.last_name.max' => 'Last name must be under 40 characters',
            'order.first_name.required' => __('First name is required'),
            'order.last_name.required' => __('Last name is required'),
            'order.email' => __('A valid email is required'),
            'order.email_confirmation.required' => __('Please confirm your email address'),
            'order.email_confirmation.same' => __('Email addresses do not match'),
            'order.address.address_line_1.required' => __('Address line 1 is required'),
            'order.address.city.required' => __('City is required'),
            'order.address.zip_or_postal_code.required' => __('Zip or postal code is required'),
            'order.address.country.required' => __('Country is required'),
            'products.*.birth_date.date_format' => __('Please enter a valid date of birth.'),
        ];
    }
}
