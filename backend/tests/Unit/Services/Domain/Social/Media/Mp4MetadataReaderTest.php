<?php

namespace Tests\Unit\Services\Domain\Social\Media;

use HiEvents\Services\Domain\Social\Media\Mp4MetadataReader;
use Tests\TestCase;

class Mp4MetadataReaderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/Social/'.$name);
    }

    public function test_reads_size_duration_and_codecs_of_a_vertical_video(): void
    {
        $metadata = (new Mp4MetadataReader)->read($this->fixture('vertical-9x16.mp4'));

        $this->assertSame(108, $metadata->width);
        $this->assertSame(192, $metadata->height);
        $this->assertEqualsWithDelta(4000, $metadata->durationMs, 150);
        $this->assertSame('avc1', $metadata->videoCodec);
        $this->assertSame('mp4a', $metadata->audioCodec);
    }

    public function test_finds_the_moov_box_at_the_end_of_the_file(): void
    {
        $metadata = (new Mp4MetadataReader)->read($this->fixture('horizontal-16x9-moov-at-end.mp4'));

        $this->assertSame(192, $metadata->width);
        $this->assertSame(108, $metadata->height);
        $this->assertNull($metadata->audioCodec);
    }

    public function test_applies_the_rotation_of_a_phone_video(): void
    {
        $metadata = (new Mp4MetadataReader)->read($this->fixture('rotated.mov'));

        $this->assertSame(108, $metadata->width);
        $this->assertSame(192, $metadata->height);
    }

    public function test_returns_null_for_a_file_that_is_not_a_video(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-a-video');
        file_put_contents($path, str_repeat('x', 64));

        $this->assertNull((new Mp4MetadataReader)->read($path));

        unlink($path);
    }
}
