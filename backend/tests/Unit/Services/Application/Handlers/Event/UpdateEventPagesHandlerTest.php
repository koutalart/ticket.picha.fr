<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventPagesDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventPagesHandler;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use HiEvents\Services\Domain\Event\EventPagesService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class UpdateEventPagesHandlerTest extends TestCase
{
    private EventSettingsRepositoryInterface $settingsRepository;

    private ImageRepositoryInterface $imageRepository;

    private UpdateEventPagesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.cdn_url_override' => 'https://cdn.example.com']);

        $this->settingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->imageRepository = Mockery::mock(ImageRepositoryInterface::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->handler = new UpdateEventPagesHandler(
            $this->settingsRepository,
            $this->imageRepository,
            new EventPagesService(app(HtmlPurifierService::class)),
            $databaseManager,
        );
    }

    private function dto(array $artists = [], array $practicalInfo = [], array $faq = []): UpdateEventPagesDTO
    {
        return new UpdateEventPagesDTO(
            eventId: 14,
            pages: EventPagesDTO::from([
                'artists' => $artists,
                'practical_info' => $practicalInfo,
                'faq' => $faq,
            ]),
        );
    }

    public function test_stores_sanitized_pages_and_deletes_unused_artist_photos(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->with(['event_id' => 14])
            ->andReturn((new EventSettingDomainObject)->setId(3)->setEventId(14));

        $this->imageRepository->shouldReceive('findWhere')
            ->once()
            ->with([
                'entity_id' => 14,
                'entity_type' => EventDomainObject::class,
                'type' => 'EVENT_ARTIST_PHOTO',
            ])
            ->andReturn(new Collection([
                (new ImageDomainObject)->setId(5)->setPath('events/14/meli.webp'),
                (new ImageDomainObject)->setId(6)->setPath('events/14/old.webp'),
            ]));

        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(function (int $id, array $data) {
                $pages = $data['event_pages'];

                return $id === 3
                    && $pages['artists'][0]['name'] === 'Méli'
                    && $pages['artists'][0]['image_id'] === 5
                    && ! str_contains($pages['faq'][0]['answer'], '<script')
                    && $pages['practical_info'] === [];
            })
            ->andReturn(new EventSettingDomainObject);

        $this->imageRepository->shouldReceive('deleteWhere')
            ->once()
            ->with([
                'entity_id' => 14,
                'entity_type' => EventDomainObject::class,
                'type' => 'EVENT_ARTIST_PHOTO',
                ['id', 'in', [6]],
            ])
            ->andReturn(1);

        $result = $this->handler->handle($this->dto(
            artists: [['name' => 'Méli', 'image_id' => 5]],
            faq: [['question' => 'Est-ce gratuit ?', 'answer' => '<p>Oui</p><script>alert(1)</script>']],
        ));

        $this->assertSame(['artistes', 'faq'], $result->available_pages);
        $this->assertStringEndsWith('events/14/meli.webp', $result->artists[0]->photo_url);
    }

    public function test_clearing_every_page_stores_null(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn((new EventSettingDomainObject)->setId(3)->setEventId(14));
        $this->imageRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->imageRepository->shouldNotReceive('deleteWhere');

        $this->settingsRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(3, ['event_pages' => null])
            ->andReturn(new EventSettingDomainObject);

        $result = $this->handler->handle($this->dto());

        $this->assertSame([], $result->available_pages);
    }

    public function test_throws_when_settings_are_missing(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->settingsRepository->shouldNotReceive('updateFromArray');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle($this->dto());
    }
}
