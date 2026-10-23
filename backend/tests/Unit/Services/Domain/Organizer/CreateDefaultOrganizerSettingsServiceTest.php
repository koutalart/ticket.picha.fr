<?php

namespace Tests\Unit\Services\Domain\Organizer;

use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
use HiEvents\DomainObjects\Enums\PriceDisplayMode;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Domain\Organizer\CreateDefaultOrganizerSettingsService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class CreateDefaultOrganizerSettingsServiceTest extends TestCase
{
    public function test_new_organizers_collect_attendee_details_per_order_and_show_marketing_opt_in(): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static fn (array $attributes) => $attributes['organizer_id'] === 7
                && $attributes['default_attendee_details_collection_method'] === AttendeeDetailsCollectionMethod::PER_ORDER->name
                && $attributes['default_show_marketing_opt_in'] === true));

        (new CreateDefaultOrganizerSettingsService($repository))
            ->createOrganizerSettings((new OrganizerDomainObject)->setId(7));
    }

    public function test_new_organizers_pass_the_platform_fee_to_the_buyer(): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static fn (array $attributes) => $attributes['default_pass_platform_fee_to_buyer'] === true));

        (new CreateDefaultOrganizerSettingsService($repository))
            ->createOrganizerSettings((new OrganizerDomainObject)->setId(7));
    }

    public function test_new_events_pass_the_platform_fee_to_the_buyer_by_default(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(1, 2, (new OrganizerDomainObject)->setId(7)->setName('Konka Wawa')->setEmail('wawa@example.com'));

        $this->assertTrue($dto->pass_platform_fee_to_buyer);
    }

    public function test_new_organizers_let_attendees_edit_their_ticket_details(): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static fn (array $attributes) => $attributes['default_allow_attendee_self_edit'] === true));

        (new CreateDefaultOrganizerSettingsService($repository))
            ->createOrganizerSettings((new OrganizerDomainObject)->setId(7));
    }

    public function test_new_organizers_get_the_default_sms_reminders(): void
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static fn (array $attributes) => $attributes['sms_default_reminders_enabled'] === true));

        (new CreateDefaultOrganizerSettingsService($repository))
            ->createOrganizerSettings((new OrganizerDomainObject)->setId(7));
    }

    public function test_existing_organizers_keep_the_default_sms_reminders_off(): void
    {
        $default = DB::table('information_schema.columns')
            ->where('table_name', 'organizer_settings')
            ->where('column_name', 'sms_default_reminders_enabled')
            ->value('column_default');

        $this->assertSame('false', $default);
        $this->assertFalse((new OrganizerSettingDomainObject)->getSmsDefaultRemindersEnabled());
    }

    public function test_new_events_show_taxes_and_fees_separately_and_allow_self_edit(): void
    {
        $dto = UpdateEventSettingsDTO::createWithDefaults(1, 2, (new OrganizerDomainObject)->setId(7)->setName('Konka Wawa')->setEmail('wawa@example.com'));

        $this->assertSame(PriceDisplayMode::EXCLUSIVE, $dto->price_display_mode);
        $this->assertTrue($dto->allow_attendee_self_edit);
    }
}
