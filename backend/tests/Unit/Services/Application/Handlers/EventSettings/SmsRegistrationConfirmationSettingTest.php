<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Jobs\Sms\SendSmsRegistrationConfirmationJob;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\EventSettings\UpdateEventSettingsHandler;
use HiEvents\Services\Domain\Sms\RegistrationConfirmation\SmsRegistrationConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class SmsRegistrationConfirmationSettingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_disabled_by_default(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(
            1,
            2,
            (new OrganizerDomainObject)->setId(7)->setName('Kultur')->setEmail('kultur@example.com'),
        );

        $this->assertFalse($dto->sms_registration_confirmation_enabled);
        $this->assertNull($dto->sms_registration_confirmation_message);
        $this->assertFalse((new EventSettingDomainObject)->getSmsRegistrationConfirmationEnabled());
    }

    public function test_partial_update_passes_new_values_through(): void
    {
        $dto = $this->runPartialUpdate([
            'sms_registration_confirmation_enabled' => true,
            'sms_registration_confirmation_message' => 'Merci {prenom}',
        ]);

        $this->assertTrue($dto->sms_registration_confirmation_enabled);
        $this->assertSame('Merci {prenom}', $dto->sms_registration_confirmation_message);
    }

    public function test_partial_update_keeps_existing_values_when_omitted(): void
    {
        $dto = $this->runPartialUpdate([]);

        $this->assertTrue($dto->sms_registration_confirmation_enabled);
        $this->assertSame('Existant {lien}', $dto->sms_registration_confirmation_message);
    }

    public function test_request_limits_the_message(): void
    {
        $rules = array_filter(
            (new UpdateEventSettingsRequest())->rules(),
            fn (string $key) => str_starts_with($key, 'sms_registration_confirmation'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertFalse(Validator::make([
            'sms_registration_confirmation_enabled' => true,
            'sms_registration_confirmation_message' => 'Bonjour {prenom}',
        ], $rules)->fails());

        $invalid = Validator::make([
            'sms_registration_confirmation_enabled' => 'oui',
            'sms_registration_confirmation_message' => str_repeat('a', 1001),
        ], $rules);
        $this->assertTrue($invalid->errors()->has('sms_registration_confirmation_enabled'));
        $this->assertTrue($invalid->errors()->has('sms_registration_confirmation_message'));
    }

    public function test_resource_exposes_the_settings(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setSmsRegistrationConfirmationEnabled(true)
            ->setSmsRegistrationConfirmationMessage('Merci {prenom}');

        $resource = (new EventSettingsResource($settings))->toArray(Request::create('/'));

        $this->assertTrue($resource['sms_registration_confirmation_enabled']);
        $this->assertSame('Merci {prenom}', $resource['sms_registration_confirmation_message']);
    }

    public function test_default_message_is_in_french_with_the_variables(): void
    {
        $this->assertSame(
            "C'est validé, votre place pour {evenement} est réservée. {date}, accueil dès {heure}. Gérer ma venue : {lien}",
            SmsRegistrationConfirmationService::defaultMessage(),
        );
    }

    public function test_the_job_logs_errors_without_failing(): void
    {
        $service = Mockery::mock(SmsRegistrationConfirmationService::class);
        $service->shouldReceive('sendForOrder')->with(40)->andThrow(new RuntimeException('Base indisponible'));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('SMS registration confirmations failed', Mockery::type('array'));

        (new SendSmsRegistrationConfirmationJob(40))->handle($service, $logger);
    }

    private function runPartialUpdate(array $settings): UpdateEventSettingsDTO
    {
        $existingSettings = (new EventSettingDomainObject)
            ->setSmsRegistrationConfirmationEnabled(true)
            ->setSmsRegistrationConfirmationMessage('Existant {lien}')
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
