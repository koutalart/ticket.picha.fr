<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\EventAssistant;

use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Services\Domain\EventAssistant\EventAssistantService;
use HiEvents\Services\Infrastructure\AI\ClaudeStructuredOutputClient;
use Illuminate\Http\UploadedFile;
use Imagick;
use Mockery;
use Tests\TestCase;

class EventAssistantServiceTest extends TestCase
{
    private function poster(int $width = 3000, int $height = 4000): UploadedFile
    {
        $image = new Imagick;
        $image->newImage($width, $height, 'white');
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'poster').'.png';
        file_put_contents($path, $image->getImageBlob());

        return new UploadedFile($path, 'affiche.png', 'image/png', null, true);
    }

    private function service(array $answer, ?array &$sent = null): EventAssistantService
    {
        $claude = Mockery::mock(ClaudeStructuredOutputClient::class);
        $claude->shouldReceive('ask')->once()->andReturnUsing(function (string $system, array $content, array $schema) use ($answer, &$sent) {
            $sent = ['system' => $system, 'content' => $content, 'schema' => $schema];

            return $answer;
        });

        return new EventAssistantService($claude);
    }

    public function test_poster_answer_is_cleaned_before_reaching_the_form(): void
    {
        $draft = $this->service([
            'title' => '  <b>Journée portes ouvertes</b> ',
            'start_date' => '2026-10-18T10:00',
            'end_date' => '2026-10-18T16:00',
            'venue' => 'Mayotte la 1ère, Pamandzi',
            'description' => 'Venez découvrir les coulisses.',
            'price' => 0,
        ], $sent)->readPoster($this->poster(), 'Indian/Mayotte');

        self::assertSame('Journée portes ouvertes', $draft->title);
        self::assertSame('2026-10-18T10:00', $draft->start_date);
        self::assertSame('2026-10-18T16:00', $draft->end_date);
        self::assertSame(0.0, $draft->price);

        $image = new Imagick;
        $image->readImageBlob(base64_decode($sent['content'][0]['source']['data']));
        self::assertSame('image/jpeg', $sent['content'][0]['source']['mediaType']);
        self::assertLessThanOrEqual(1568, max($image->getImageWidth(), $image->getImageHeight()), 'the poster is downsized before being sent');
        self::assertStringContainsString('never guess', $sent['system']);
        self::assertStringContainsString('Never write a date', $sent['system']);
    }

    public function test_invalid_values_are_dropped_instead_of_guessed(): void
    {
        $draft = $this->service([
            'title' => null,
            'start_date' => 'samedi 10 octobre',
            'end_date' => '2026-10-10T09:00',
            'venue' => '',
            'description' => null,
            'price' => -5,
        ])->readPoster($this->poster(800, 600), 'Indian/Mayotte');

        self::assertNull($draft->title);
        self::assertNull($draft->start_date);
        self::assertNull($draft->end_date, 'no end date without a valid start date');
        self::assertNull($draft->venue);
        self::assertNull($draft->price);
    }

    public function test_end_date_before_start_is_dropped(): void
    {
        $draft = $this->service([
            'title' => 'Concert', 'start_date' => '2026-10-11T18:00', 'end_date' => '2026-10-11T17:00',
            'venue' => null, 'description' => null, 'price' => 45,
        ])->readPoster($this->poster(800, 600), 'Indian/Mayotte');

        self::assertSame('2026-10-11T18:00', $draft->start_date);
        self::assertNull($draft->end_date);
        self::assertSame(45.0, $draft->price);
    }

    public function test_improved_description_must_not_be_empty(): void
    {
        $this->expectException(EventAssistantFailedException::class);

        $this->service(['description' => '   '])->improveDescription('Une soirée au 5/5 avec des DJ.', 'Triangle', null);
    }
}
