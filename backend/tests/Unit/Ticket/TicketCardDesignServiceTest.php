<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Exceptions\InvalidTicketCardDesignException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Domain\Ticket\TicketCardDesignService;
use HiEvents\Services\Domain\Ticket\TicketCardRenderer;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Mockery;
use Psr\Log\NullLogger;
use Tests\TestCase;

class TicketCardDesignServiceTest extends TestCase
{
    private const DISK = 'ticket-card-private';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['filesystems.private' => self::DISK]);
        $this->directory = sys_get_temp_dir().'/'.uniqid('ticket-card-', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);

        parent::tearDown();
    }

    private function backgroundFile(): string
    {
        $image = new Imagick;
        $image->newImage(1600, 2000, new ImagickPixel('#0b2340'));
        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->rectangle(353, 1572, 1247, 1808);
        $image->drawImage($draw);
        $path = $this->directory.'/Carton Fond.png';
        $image->writeImage('png:'.$path);

        return $path;
    }

    private function service(EventSettingsRepositoryInterface $repository): TicketCardDesignService
    {
        return new TicketCardDesignService(
            $repository,
            app('filesystem'),
            new TicketCardRenderer(app('filesystem'), new NullLogger),
            app('config'),
        );
    }

    public function test_store_uploads_the_background_detects_the_frame_and_saves_the_settings(): void
    {
        $repository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->with(['event_id' => 14])->andReturn(new EventSettingDomainObject);

        $saved = null;
        $repository->shouldReceive('updateWhere')
            ->once()
            ->withArgs(function (array $attributes, array $where) use (&$saved) {
                $saved = json_decode($attributes['ticket_card_design'], true);

                return $where === ['event_id' => 14];
            })
            ->andReturn(1);

        $design = $this->service($repository)->store(14, [
            'background' => $this->backgroundFile(),
            'guests' => 2,
            'dress_code' => 'bleu nuit, champagne et doré',
        ]);

        self::assertSame($design, $saved);
        self::assertSame(self::DISK, $design['disk']);
        self::assertMatchesRegularExpression('#^ticket-cards/event-14/[0-9a-f]{10}-carton-fond\.png$#', $design['background_path']);
        Storage::disk(self::DISK)->assertExists($design['background_path']);
        self::assertSame(['x' => 353, 'y' => 1572, 'width' => 895, 'height' => 237], $design['frame']);
        self::assertSame(2, $design['guests']);
        self::assertSame('bleu nuit, champagne et doré', $design['dress_code']);
    }

    public function test_a_font_must_be_a_real_font_file(): void
    {
        $repository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject);
        $repository->shouldNotReceive('updateWhere');

        $fake = $this->directory.'/police.ttf';
        file_put_contents($fake, 'not a font');

        $this->expectException(InvalidTicketCardDesignException::class);
        $this->service($repository)->store(14, ['background' => $this->backgroundFile(), 'fonts' => ['bold' => $fake]]);
    }

    public function test_a_card_needs_a_background(): void
    {
        $repository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject);
        $repository->shouldNotReceive('updateWhere');

        $this->expectException(InvalidTicketCardDesignException::class);
        $this->service($repository)->store(14, ['guests' => 2]);
    }
}
