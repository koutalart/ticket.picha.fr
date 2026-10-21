<?php

namespace Tests\Unit\Services\Domain\Question;

use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileUrlGenerator;
use HiEvents\Services\Domain\Question\QuestionAnswerFormatter;
use Mockery;
use Tests\TestCase;

class QuestionAnswerFormatterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_file_answer_shows_the_filename_and_a_signed_url(): void
    {
        $urlGenerator = Mockery::mock(QuestionAnswerFileUrlGenerator::class);
        $urlGenerator->shouldReceive('getDownloadUrlFromAnswer')
            ->once()
            ->andReturn('https://example.com/question-files/4/download?signature=abc');

        $formatter = new QuestionAnswerFormatter($urlGenerator);

        $this->assertSame(
            'cv.pdf https://example.com/question-files/4/download?signature=abc',
            $formatter->getAnswerAsText(['file_id' => 4, 'name' => 'cv.pdf'], QuestionTypeEnum::FILE),
        );
    }

    public function test_file_answer_without_url_shows_only_the_filename(): void
    {
        $urlGenerator = Mockery::mock(QuestionAnswerFileUrlGenerator::class);
        $urlGenerator->shouldNotReceive('getDownloadUrlFromAnswer');

        $formatter = new QuestionAnswerFormatter($urlGenerator);

        $this->assertSame(
            'cv.pdf',
            $formatter->getAnswerAsText(['file_id' => 4, 'name' => 'cv.pdf'], QuestionTypeEnum::FILE, includeFileUrl: false),
        );
    }

    public function test_signed_download_url_points_to_the_download_route(): void
    {
        $url = app(QuestionAnswerFileUrlGenerator::class)->getDownloadUrl(4, now()->addHour());

        $this->assertStringContainsString('/question-files/4/download?expires=', $url);
        $this->assertStringContainsString('signature=', $url);
    }
}
