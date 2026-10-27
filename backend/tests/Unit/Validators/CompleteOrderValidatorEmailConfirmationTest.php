<?php

namespace Tests\Unit\Validators;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use HiEvents\Validators\CompleteOrderValidator;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Tests\TestCase;

class CompleteOrderValidatorEmailConfirmationTest extends TestCase
{
    public function test_order_without_email_confirmation_is_accepted(): void
    {
        $validator = $this->validate([]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_older_client_sending_matching_email_confirmation_is_accepted(): void
    {
        $validator = $this->validate(['email_confirmation' => 'jo@example.com']);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_mismatching_email_confirmation_is_still_rejected(): void
    {
        $validator = $this->validate(['email_confirmation' => 'other@example.com']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.email_confirmation', $validator->errors()->toArray());
    }

    private function validate(array $orderOverrides): \Illuminate\Validation\Validator
    {
        $questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $questionRepository->shouldReceive('loadRelation')->andReturnSelf();
        $questionRepository->shouldReceive('findWhere')->andReturn(collect());

        $productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $productRepository->shouldReceive('findWhere')->andReturn(collect());

        $eventSettings = Mockery::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getRequireBillingAddress')->andReturn(false);
        $eventSettings->shouldReceive('getAttendeeDetailsCollectionMethod')->andReturn('PER_TICKET');

        $eventSettingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($eventSettings);

        $orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $route = Mockery::mock(Route::class);
        $route->shouldReceive('parameter')->andReturnUsing(fn(string $name) => match ($name) {
            'event_id' => 7,
            'order_short_id' => 'o_test',
            default => null,
        });

        $completeOrderValidator = new CompleteOrderValidator(
            $questionRepository,
            $productRepository,
            $eventSettingsRepository,
            $orderRepository,
            Mockery::mock(QuestionAnswerFileService::class),
            $route,
        );

        $data = [
            'order' => array_merge([
                'first_name' => 'Jo',
                'last_name' => 'K',
                'email' => 'jo@example.com',
                'questions' => [],
            ], $orderOverrides),
            'products' => [],
        ];

        return Validator::make($data, $completeOrderValidator->rules(), $completeOrderValidator->messages());
    }
}
