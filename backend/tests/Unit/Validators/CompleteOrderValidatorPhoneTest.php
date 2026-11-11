<?php

namespace Tests\Unit\Validators;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
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

class CompleteOrderValidatorPhoneTest extends TestCase
{
    public function test_phone_is_required_when_the_checkout_sends_it(): void
    {
        $validator = $this->validate(['phone' => '']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.phone', $validator->errors()->toArray());
    }

    public function test_valid_phone_is_accepted(): void
    {
        $validator = $this->validate(['phone' => '0639 12 34 56']);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_unusable_phone_is_rejected(): void
    {
        $validator = $this->validate(['phone' => '12']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('order.phone', $validator->errors()->toArray());
    }

    public function test_flows_that_do_not_send_the_phone_are_not_blocked(): void
    {
        $validator = $this->validate([]);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_phone_field_is_optional_when_the_event_has_its_own_phone_question(): void
    {
        $question = (new QuestionDomainObject())
            ->setId(5)
            ->setType('PHONE')
            ->setBelongsTo('ORDER')
            ->setRequired(false)
            ->setTitle('Téléphone');

        $validator = $this->validate(['phone' => ''], [$question]);

        $this->assertArrayNotHasKey('order.phone', $validator->errors()->toArray());
    }

    private function validate(array $orderOverrides, array $questions = []): \Illuminate\Validation\Validator
    {
        $questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $questionRepository->shouldReceive('loadRelation')->andReturnSelf();
        $questionRepository->shouldReceive('findWhere')->andReturn(collect($questions));

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
