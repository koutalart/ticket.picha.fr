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

class CompleteOrderValidatorOptionalLastNameTest extends TestCase
{
    public function test_order_without_last_name_is_accepted(): void
    {
        $validator = $this->validate(['last_name' => null]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_order_with_empty_last_name_is_accepted(): void
    {
        $validator = $this->validate(['last_name' => '']);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_first_name_is_still_required(): void
    {
        $validator = $this->validate(['first_name' => null, 'last_name' => null]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.first_name', $validator->errors()->toArray());
        $this->assertArrayNotHasKey('order.last_name', $validator->errors()->toArray());
    }

    public function test_too_long_last_name_is_still_rejected(): void
    {
        $validator = $this->validate(['last_name' => str_repeat('a', 41)]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.last_name', $validator->errors()->toArray());
    }

    public function test_email_is_still_required(): void
    {
        $validator = $this->validate(['email' => null]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.email', $validator->errors()->toArray());
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
        $route->shouldReceive('parameter')->andReturnUsing(fn (string $name) => match ($name) {
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
