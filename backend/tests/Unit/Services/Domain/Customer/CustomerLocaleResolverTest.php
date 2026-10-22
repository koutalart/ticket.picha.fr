<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Customer;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CustomerLocaleResolverTest extends TestCase
{
    private OrganizerSettingsRepositoryInterface|MockInterface $organizerSettingsRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private CustomerLocaleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.customer_locale' => 'fr']);
        $this->organizerSettingsRepository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->resolver = new CustomerLocaleResolver($this->organizerSettingsRepository, $this->eventRepository);
    }

    private function organizerSays(int $organizerId, ?string $addressForm): void
    {
        $this->organizerSettingsRepository->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => $organizerId])
            ->andReturn($addressForm === null
                ? null
                : (new OrganizerSettingDomainObject)->setId(1)->setOrganizerId($organizerId)->setCustomerAddressForm($addressForm));
    }

    public function test_an_organizer_in_tu_gets_french_tutoiement(): void
    {
        $this->organizerSays(4, 'tu');

        self::assertSame(CustomerAddressForm::TU, $this->resolver->addressFormForOrganizerId(4));
        self::assertSame(CustomerLocale::FRENCH_TUTOIEMENT, $this->resolver->forOrganizerId(4));
        self::assertSame(CustomerLocale::FRENCH_TUTOIEMENT, $this->resolver->forEvent((new EventDomainObject)->setId(9)->setOrganizerId(4)));
    }

    public function test_an_organizer_in_vous_keeps_the_customer_locale(): void
    {
        $this->organizerSays(8, 'vous');

        self::assertSame(CustomerAddressForm::VOUS, $this->resolver->addressFormForOrganizerId(8));
        self::assertSame('fr', $this->resolver->forOrganizerId(8));
    }

    public function test_missing_or_unknown_settings_fall_back_to_vous(): void
    {
        $this->organizerSays(5, null);
        $this->organizerSays(6, 'toi');
        $this->organizerSettingsRepository->shouldReceive('findFirstWhere')->with(['organizer_id' => 7])->andThrow(new RuntimeException('down'));

        self::assertSame('fr', $this->resolver->forOrganizerId(5));
        self::assertSame('fr', $this->resolver->forOrganizerId(6));
        self::assertSame('fr', $this->resolver->forOrganizerId(7));
        self::assertSame('fr', $this->resolver->forOrganizerId(null));
        self::assertSame('fr', $this->resolver->forEvent(null));
    }

    public function test_an_event_follows_its_organizer(): void
    {
        $this->organizerSays(4, 'tu');
        $this->eventRepository->shouldReceive('findFirst')->with(12)->andReturn((new EventDomainObject)->setId(12)->setOrganizerId(4));
        $this->eventRepository->shouldReceive('findFirst')->with(13)->andReturnNull();

        self::assertSame(CustomerLocale::FRENCH_TUTOIEMENT, $this->resolver->forEventId(12));
        self::assertSame('fr', $this->resolver->forEventId(13));
        self::assertSame('fr', $this->resolver->forEventId(null));
    }

    public function test_tutoiement_only_applies_to_french(): void
    {
        config(['app.customer_locale' => 'en']);
        $this->organizerSays(4, 'tu');

        self::assertSame('en', $this->resolver->forOrganizerId(4));
    }

    public function test_formatting_uses_the_parent_locale(): void
    {
        self::assertSame('fr', CustomerLocale::formattingLocale(CustomerLocale::FRENCH_TUTOIEMENT));
        self::assertSame('fr', CustomerLocale::formattingLocale('fr'));
        self::assertSame('en', CustomerLocale::formattingLocale('en'));
        self::assertTrue(CustomerLocale::isTutoiement('fr_TU'));
        self::assertFalse(CustomerLocale::isTutoiement('fr'));
    }
}
