<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\EventSettings\UpdateEventSettingsHandler;
use HiEvents\Services\Domain\Sms\CancellationConfirmation\SmsCancellationConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SmsCancellationConfirmationSettingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_disabled_by_default(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(
            1,
            2,
            (new OrganizerDomainObject)->setId(7)->setName('Kultur')->setEmail('kultur@example.com'),
        );

        $this->assertFalse($dto->sms_cancellation_confirmation_enabled);
        $this->assertNull($dto->sms_cancellation_confirmation_message);
        $this->assertFalse($dto->sms_cancellation_confirmation_on_organizer_cancel);
        $this->assertFalse((new EventSettingDomainObject)->getSmsCancellationConfirmationEnabled());
        $this->assertFalse((new EventSettingDomainObject)->getSmsCancellationConfirmationOnOrganizerCancel());
    }

    public function test_partial_update_passes_new_values_through(): void
    {
        $dto = $this->runPartialUpdate([
            'sms_cancellation_confirmation_enabled' => false,
            'sms_cancellation_confirmation_message' => null,
            'sms_cancellation_confirmation_on_organizer_cancel' => false,
        ]);

        $this->assertFalse($dto->sms_cancellation_confirmation_enabled);
        $this->assertNull($dto->sms_cancellation_confirmation_message);
        $this->assertFalse($dto->sms_cancellation_confirmation_on_organizer_cancel);
    }

    public function test_partial_update_keeps_existing_values_when_omitted(): void
    {
        $dto = $this->runPartialUpdate([]);

        $this->assertTrue($dto->sms_cancellation_confirmation_enabled);
        $this->assertSame('Existant {lien}', $dto->sms_cancellation_confirmation_message);
        $this->assertTrue($dto->sms_cancellation_confirmation_on_organizer_cancel);
    }

    public function test_request_validates_the_settings(): void
    {
        $rules = array_filter(
            (new UpdateEventSettingsRequest())->rules(),
            fn (string $key) => str_starts_with($key, 'sms_cancellation_confirmation'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertFalse(Validator::make([
            'sms_cancellation_confirmation_enabled' => true,
            'sms_cancellation_confirmation_message' => 'À bientôt {prenom}',
            'sms_cancellation_confirmation_on_organizer_cancel' => false,
        ], $rules)->fails());

        $invalid = Validator::make([
            'sms_cancellation_confirmation_enabled' => 'oui',
            'sms_cancellation_confirmation_message' => str_repeat('a', 1001),
            'sms_cancellation_confirmation_on_organizer_cancel' => 'non',
        ], $rules);
        $this->assertTrue($invalid->errors()->has('sms_cancellation_confirmation_enabled'));
        $this->assertTrue($invalid->errors()->has('sms_cancellation_confirmation_message'));
        $this->assertTrue($invalid->errors()->has('sms_cancellation_confirmation_on_organizer_cancel'));
    }

    public function test_resource_exposes_the_settings(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setSmsCancellationConfirmationEnabled(true)
            ->setSmsCancellationConfirmationMessage('À bientôt {prenom}')
            ->setSmsCancellationConfirmationOnOrganizerCancel(true);

        $resource = (new EventSettingsResource($settings))->toArray(Request::create('/'));

        $this->assertTrue($resource['sms_cancellation_confirmation_enabled']);
        $this->assertSame('À bientôt {prenom}', $resource['sms_cancellation_confirmation_message']);
        $this->assertTrue($resource['sms_cancellation_confirmation_on_organizer_cancel']);
    }

    public function test_default_message_is_generic_and_in_french(): void
    {
        $message = SmsCancellationConfirmationService::defaultMessage();

        $this->assertSame(
            "C'est noté, votre inscription à {evenement} est annulée et votre place est libérée. Au plaisir de vous revoir.",
            $message,
        );
        $this->assertStringNotContainsString('Kultur', $message);
    }

    private function runPartialUpdate(array $settings): UpdateEventSettingsDTO
    {
        $existingSettings = (new EventSettingDomainObject)
            ->setSmsCancellationConfirmationEnabled(true)
            ->setSmsCancellationConfirmationMessage('Existant {lien}')
            ->setSmsCancellationConfirmationOnOrganizerCancel(true)
            ->setPaymentProviders([]);

        $repository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->with(['event_id' => 1])->andReturn($existingSettings);

        $captured = null;
        $fullHandler = Mockery::mock(UpdateEventSettingsHandler::class);
        $fullHandler->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (UpdateEventSettingsDTO $dto) use (&$captured, $existingSettings) {
                $captured = $dto;

                return $existingSettings;
            });

        (new PartialUpdateEventSettingsHandler($fullHandler, $repository))->handle(new PartialUpdateEventSettingsDTO(
            account_id: 1,
            event_id: 1,
            settings: array_merge(['location_details' => []], $settings),
        ));

        return $captured;
    }
}
