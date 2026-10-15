<?php

namespace Tests\Unit\Services\Domain\QrLink;

use HiEvents\DomainObjects\Enums\QrPosterLogo;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\QrLink\QrPosterLogoProvider;
use Illuminate\Filesystem\FilesystemManager;
use Mockery;
use Tests\TestCase;

class QrPosterLogoProviderTest extends TestCase
{
    public function test_every_icon_logo_has_its_svg(): void
    {
        $provider = new QrPosterLogoProvider(Mockery::mock(ImageRepositoryInterface::class), Mockery::mock(FilesystemManager::class));

        foreach (QrPosterLogo::cases() as $logo) {
            if ($logo === QrPosterLogo::ORGANIZER) {
                continue;
            }

            $result = $provider->get($logo->value, 8);
            $this->assertNotNull($result, $logo->value);
            $this->assertTrue($result->isIcon);
            $this->assertStringContainsString('<svg', $result->blob);
        }
    }

    public function test_unknown_or_empty_logo_gives_nothing(): void
    {
        $provider = new QrPosterLogoProvider(Mockery::mock(ImageRepositoryInterface::class), Mockery::mock(FilesystemManager::class));

        $this->assertNull($provider->get(null, 8));
        $this->assertNull($provider->get('myspace', 8));
    }

    public function test_organizer_without_logo_gives_nothing(): void
    {
        $images = Mockery::mock(ImageRepositoryInterface::class);
        $images->shouldReceive('findFirstWhere')->andReturnNull();

        $this->assertNull((new QrPosterLogoProvider($images, Mockery::mock(FilesystemManager::class)))->get('organizer', 8));
    }
}
