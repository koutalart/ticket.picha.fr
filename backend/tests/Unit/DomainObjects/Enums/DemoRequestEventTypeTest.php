<?php

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\DemoRequestEventType;
use HiEvents\DomainObjects\Enums\EventCategory;
use Tests\TestCase;

class DemoRequestEventTypeTest extends TestCase
{
    public function testEveryDemoEventTypeIsAnEventCategory(): void
    {
        foreach (DemoRequestEventType::cases() as $type) {
            $this->assertNotNull(EventCategory::tryFrom($type->value), $type->value);
        }
    }

    public function testLabelsAreTheEventCategoryLabels(): void
    {
        app()->setLocale('fr');

        $this->assertSame('Journée portes ouvertes', DemoRequestEventType::OPEN_HOUSE->label());
        $this->assertSame('Remise de prix', DemoRequestEventType::AWARDS->label());
        $this->assertSame(EventCategory::FESTIVAL->label(), DemoRequestEventType::FESTIVAL->label());
    }
}
