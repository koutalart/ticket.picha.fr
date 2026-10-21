<?php

namespace Tests\Unit\Services\Domain\Organizer\Font;

use HiEvents\DomainObjects\Enums\OrganizerFontFormat;
use HiEvents\DomainObjects\Enums\OrganizerFontWeight;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Exceptions\InvalidOrganizerFontException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerFontRepositoryInterface;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery as m;
use Psr\Log\NullLogger;
use Tests\TestCase;

class OrganizerFontServiceTest extends TestCase
{
    private const DISK = 'organizer-font-test';

    private OrganizerFontRepositoryInterface $fontRepository;

    private EventRepositoryInterface $eventRepository;

    private OrganizerFontService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.public' => self::DISK]);
        Storage::fake(self::DISK);

        $this->fontRepository = m::mock(OrganizerFontRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $this->service = new OrganizerFontService(
            $this->fontRepository,
            $this->eventRepository,
            app(FilesystemManager::class),
            app('config'),
            $databaseManager,
            new NullLogger,
        );
    }

    private function fontFile(string $name, string $signature): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $signature.str_repeat("\x00", 64));
    }

    private function font(int $id, int $weight, string $path = 'organizer_fonts/8/old.woff2'): OrganizerFontDomainObject
    {
        return (new OrganizerFontDomainObject)
            ->setId($id)
            ->setOrganizerId(8)
            ->setAccountId(3)
            ->setFamilyName('franceTV Brown')
            ->setWeight($weight)
            ->setFormat('woff2')
            ->setFilename('old.woff2')
            ->setDisk(self::DISK)
            ->setPath($path)
            ->setSize(1000);
    }

    public function test_detects_each_supported_format_from_its_signature(): void
    {
        $this->assertSame(OrganizerFontFormat::WOFF2, $this->service->detectFormat($this->fontFile('a.woff2', 'wOF2')));
        $this->assertSame(OrganizerFontFormat::WOFF, $this->service->detectFormat($this->fontFile('a.woff', 'wOFF')));
        $this->assertSame(OrganizerFontFormat::TRUETYPE, $this->service->detectFormat($this->fontFile('a.ttf', "\x00\x01\x00\x00")));
        $this->assertSame(OrganizerFontFormat::OPENTYPE, $this->service->detectFormat($this->fontFile('a.otf', 'OTTO')));
        $this->assertSame(OrganizerFontFormat::TRUETYPE, $this->service->detectFormat($this->fontFile('a.otf', "\x00\x01\x00\x00")));
    }

    public function test_rejects_a_file_that_is_not_a_font_despite_its_extension(): void
    {
        $this->expectException(InvalidOrganizerFontException::class);

        $this->service->detectFormat($this->fontFile('logo.woff2', "\x89PNG"));
    }

    public function test_rejects_a_font_whose_extension_does_not_match_its_content(): void
    {
        $this->expectException(InvalidOrganizerFontException::class);

        $this->service->detectFormat($this->fontFile('brown.woff2', 'wOFF'));
    }

    public function test_rejects_a_truncated_file(): void
    {
        $this->expectException(InvalidOrganizerFontException::class);

        $this->service->detectFormat(UploadedFile::fake()->createWithContent('brown.woff2', 'wOF2'));
    }

    public function test_upload_stores_the_file_publicly_and_replaces_the_previous_file_of_the_same_weight(): void
    {
        Storage::disk(self::DISK)->put('organizer_fonts/8/old.woff2', 'old');
        $previous = $this->font(5, 700);

        $this->fontRepository->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => 8, 'weight' => 700])
            ->andReturn($previous);
        $this->fontRepository->shouldReceive('deleteById')->once()->with(5)->andReturnTrue();
        $this->fontRepository->shouldReceive('updateWhere')->once()
            ->with(['family_name' => 'franceTV Brown'], ['organizer_id' => 8])
            ->andReturn(2);

        $created = $this->font(6, 700, 'organizer_fonts/8/new.woff2');
        $captured = null;
        $this->fontRepository->shouldReceive('create')->once()
            ->with(m::on(function (array $data) use (&$captured) {
                $captured = $data;

                return true;
            }))
            ->andReturn($created);

        $result = $this->service->upload(8, 3, 'franceTV Brown', OrganizerFontWeight::Bold, $this->fontFile('Brown-Bold.woff2', 'wOF2'));

        $this->assertSame($created, $result);
        $this->assertSame(700, $captured['weight']);
        $this->assertSame('woff2', $captured['format']);
        $this->assertSame(self::DISK, $captured['disk']);
        $this->assertSame('Brown-Bold.woff2', $captured['filename']);
        $this->assertStringStartsWith('organizer_fonts/8/francetv-brown-700-', $captured['path']);
        $this->assertStringEndsWith('.woff2', $captured['path']);
        Storage::disk(self::DISK)->assertExists($captured['path']);
        Storage::disk(self::DISK)->assertMissing('organizer_fonts/8/old.woff2');
    }

    public function test_upload_rejects_a_non_font_file_before_storing_anything(): void
    {
        $this->fontRepository->shouldNotReceive('create');

        try {
            $this->service->upload(8, 3, 'franceTV Brown', OrganizerFontWeight::Regular, $this->fontFile('evil.ttf', '<?ph'));
            $this->fail('An invalid font should be rejected');
        } catch (InvalidOrganizerFontException) {
            $this->assertSame([], Storage::disk(self::DISK)->allFiles());
        }
    }

    public function test_delete_removes_the_row_and_the_file(): void
    {
        Storage::disk(self::DISK)->put('organizer_fonts/8/old.woff2', 'old');

        $this->fontRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 5, 'organizer_id' => 8])
            ->andReturn($this->font(5, 300));
        $this->fontRepository->shouldReceive('deleteById')->once()->with(5)->andReturnTrue();

        $this->service->delete(8, 5);

        Storage::disk(self::DISK)->assertMissing('organizer_fonts/8/old.woff2');
    }

    public function test_delete_refuses_a_font_of_another_organizer(): void
    {
        $this->fontRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 5, 'organizer_id' => 8])
            ->andReturnNull();
        $this->fontRepository->shouldNotReceive('deleteById');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->delete(8, 5);
    }

    public function test_font_is_available_only_once_the_regular_weight_is_uploaded(): void
    {
        $this->fontRepository->shouldReceive('findWhere')
            ->with(['organizer_id' => 8])
            ->andReturn(collect([$this->font(1, 300), $this->font(2, 700)]), collect([$this->font(1, 300), $this->font(3, 400)]));

        $this->assertFalse($this->service->isAvailableForOrganizer(8));
        $this->assertTrue($this->service->isAvailableForOrganizer(8));
    }

    public function test_font_availability_for_an_event_uses_its_organizer(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->with(14)
            ->andReturn((new EventDomainObject)->setId(14)->setOrganizerId(8));
        $this->fontRepository->shouldReceive('findWhere')
            ->with(['organizer_id' => 8])
            ->andReturn(collect([$this->font(3, 400)]));

        $this->assertTrue($this->service->isAvailableForEvent(14));
    }

    public function test_fonts_are_sorted_by_weight(): void
    {
        $this->fontRepository->shouldReceive('findWhere')
            ->andReturn(collect([$this->font(1, 900), $this->font(2, 300), $this->font(3, 400)]));

        $this->assertSame([300, 400, 900], $this->service->getFonts(8)->map->getWeight()->all());
    }
}
