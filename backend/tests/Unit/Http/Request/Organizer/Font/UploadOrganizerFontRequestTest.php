<?php

namespace Tests\Unit\Http\Request\Organizer\Font;

use HiEvents\Http\Request\Organizer\Font\UploadOrganizerFontRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UploadOrganizerFontRequestTest extends TestCase
{
    private function validate(array $data): \Illuminate\Contracts\Validation\Validator
    {
        $request = new UploadOrganizerFontRequest;

        return Validator::make($data, $request->rules(), $request->messages());
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'font_file' => UploadedFile::fake()->createWithContent('Brown-Regular.woff2', 'wOF2'.str_repeat("\x00", 64)),
            'weight' => 400,
            'family_name' => 'franceTV Brown',
        ], $overrides);
    }

    public function test_accepts_a_woff2_file_with_a_supported_weight(): void
    {
        $this->assertFalse($this->validate($this->valid())->fails());
    }

    public function test_accepts_ttf_and_otf_extensions(): void
    {
        $this->assertFalse($this->validate($this->valid([
            'font_file' => UploadedFile::fake()->createWithContent('Brown-Light.ttf', "\x00\x01\x00\x00".str_repeat("\x00", 64)),
            'weight' => 300,
        ]))->fails());
        $this->assertFalse($this->validate($this->valid([
            'font_file' => UploadedFile::fake()->createWithContent('Brown-Black.otf', 'OTTO'.str_repeat("\x00", 64)),
            'weight' => 900,
        ]))->fails());
    }

    public function test_rejects_other_extensions(): void
    {
        $validator = $this->validate($this->valid([
            'font_file' => UploadedFile::fake()->createWithContent('brown.zip', 'PK'.str_repeat("\x00", 64)),
        ]));

        $this->assertTrue($validator->errors()->has('font_file'));
    }

    public function test_rejects_files_larger_than_two_megabytes(): void
    {
        $validator = $this->validate($this->valid([
            'font_file' => UploadedFile::fake()->create('brown.woff2', 2049),
        ]));

        $this->assertTrue($validator->errors()->has('font_file'));
    }

    public function test_rejects_unsupported_weights(): void
    {
        $this->assertTrue($this->validate($this->valid(['weight' => 450]))->errors()->has('weight'));
    }

    public function test_rejects_family_names_that_could_break_css(): void
    {
        $this->assertTrue($this->validate($this->valid(['family_name' => "Brown'; } body {"]))->errors()->has('family_name'));
        $this->assertTrue($this->validate($this->valid(['family_name' => '']))->errors()->has('family_name'));
    }
}
