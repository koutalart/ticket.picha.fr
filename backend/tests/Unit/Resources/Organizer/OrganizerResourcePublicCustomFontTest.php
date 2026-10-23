<?php

namespace Tests\Unit\Resources\Organizer;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Resources\Organizer\OrganizerResourcePublic;
use Tests\TestCase;

class OrganizerResourcePublicCustomFontTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.cdn_url_override' => 'https://ticket.test/api/storage']);
    }

    private function font(int $weight, string $format = 'woff2'): OrganizerFontDomainObject
    {
        return (new OrganizerFontDomainObject)
            ->setId($weight)
            ->setOrganizerId(8)
            ->setAccountId(3)
            ->setFamilyName('franceTV Brown')
            ->setWeight($weight)
            ->setFormat($format)
            ->setFilename("brown-$weight.$format")
            ->setDisk('public')
            ->setPath("organizer_fonts/8/brown-$weight.$format")
            ->setSize(1000);
    }

    private function resolve(?\Illuminate\Support\Collection $fonts): array
    {
        $organizer = (new OrganizerDomainObject)
            ->setId(8)
            ->setName('Mayotte la 1ère')
            ->setOrganizerFonts($fonts);

        return (new OrganizerResourcePublic($organizer))->resolve();
    }

    public function test_custom_font_is_omitted_when_fonts_were_not_loaded(): void
    {
        $this->assertArrayNotHasKey('custom_font', $this->resolve(null));
    }

    public function test_custom_font_is_null_without_a_regular_file(): void
    {
        $this->assertNull($this->resolve(collect([$this->font(300), $this->font(700)]))['custom_font']);
    }

    public function test_custom_font_exposes_family_and_files_sorted_by_weight(): void
    {
        $data = $this->resolve(collect([$this->font(700), $this->font(400, 'truetype'), $this->font(300)]));

        $this->assertSame('franceTV Brown', $data['custom_font']['family_name']);
        $this->assertSame([300, 400, 700], array_column($data['custom_font']['files'], 'weight'));
        $this->assertSame('truetype', $data['custom_font']['files'][1]['format']);
        $this->assertSame(
            'https://ticket.test/api/storage/organizer_fonts/8/brown-400.truetype',
            $data['custom_font']['files'][1]['url'],
        );
    }
}
