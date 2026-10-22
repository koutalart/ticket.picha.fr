<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\Mail\OrganizerMailSenderResolver;
use Illuminate\Database\QueryException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class OrganizerMailSenderResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private OrganizerSettingsRepositoryInterface|MockInterface $settingsRepository;

    private LoggerInterface|MockInterface $logger;

    private OrganizerMailSenderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'ticket@picha.fr', 'mail.from.name' => 'PICHA Ticket']);

        $this->settingsRepository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->resolver = new OrganizerMailSenderResolver($this->settingsRepository, $this->logger);
    }

    public function test_defined_name_is_used_with_the_platform_address(): void
    {
        $this->settingsRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['organizer_id' => 7])
            ->andReturn((new OrganizerSettingDomainObject)->setEmailSenderName('Innocent Event'));

        $sender = $this->resolver->resolveForOrganizer(7);

        $this->assertNotNull($sender);
        $this->assertSame('Innocent Event', $sender->name);
        $this->assertSame('ticket@picha.fr', $sender->address);
    }

    public function test_empty_name_keeps_the_platform_default(): void
    {
        $this->settingsRepository
            ->shouldReceive('findFirstWhere')
            ->andReturn(
                (new OrganizerSettingDomainObject)->setEmailSenderName(null),
                (new OrganizerSettingDomainObject)->setEmailSenderName('   '),
                null,
            );

        $this->assertNull($this->resolver->resolveForOrganizer(7));
        $this->assertNull($this->resolver->resolveForOrganizer(7));
        $this->assertNull($this->resolver->resolveForOrganizer(7));
        $this->assertNull($this->resolver->resolveForOrganizer(null));
    }

    public function test_lookup_failure_falls_back_to_the_platform_default(): void
    {
        $this->settingsRepository
            ->shouldReceive('findFirstWhere')
            ->andThrow(new QueryException('pgsql', 'select', [], new \PDOException('down')));
        $this->logger->shouldReceive('warning')->once();

        $this->assertNull($this->resolver->resolveForOrganizer(7));
    }

    public function test_sender_name_is_sanitized(): void
    {
        $this->assertSame('Innocent Event', $this->resolver->sanitize("Innocent\r\nEvent"));
        $this->assertSame('Innocent Event', $this->resolver->sanitize("  Innocent\tEvent\0  "));
        $this->assertSame('Innocent Event', $this->resolver->sanitize('<b>Innocent</b> Event'));
        $this->assertSame('Konka a b', $this->resolver->sanitize("Konka\u{2028}a\u{200B}b"));
        $this->assertSame('Bcc: x@y.z', $this->resolver->sanitize("\nBcc: x@y.z"));
        $this->assertSame("L'Atelier & Cie", $this->resolver->sanitize("L'Atelier & Cie"));
        $this->assertSame(70, mb_strlen($this->resolver->sanitize(str_repeat('é', 90))));
        $this->assertNull($this->resolver->sanitize("\r\n\t"));
        $this->assertNull($this->resolver->sanitize(null));
    }

    public function test_sanitized_name_cannot_inject_headers(): void
    {
        $sender = $this->resolver->buildAddress("Faux\r\nBcc: victime@exemple.test");

        $this->assertNotNull($sender);
        $this->assertStringNotContainsString("\n", $sender->name);
        $this->assertStringNotContainsString("\r", $sender->name);
        $this->assertSame('ticket@picha.fr', $sender->address);
    }

    public function test_forbidden_characters_pattern_matches_validation_expectations(): void
    {
        foreach (["a\nb", "a\rb", "a\tb", 'a<b', 'a>b', "a\u{0007}b"] as $invalid) {
            $this->assertSame(1, preg_match(OrganizerMailSenderResolver::FORBIDDEN_CHARACTERS_PATTERN, $invalid), $invalid);
        }

        foreach (['Innocent Event', "L'Atelier & Cie", 'Konka Wawa 25 ans', 'Événements Mayotte'] as $valid) {
            $this->assertSame(0, preg_match(OrganizerMailSenderResolver::FORBIDDEN_CHARACTERS_PATTERN, $valid), $valid);
        }
    }
}
