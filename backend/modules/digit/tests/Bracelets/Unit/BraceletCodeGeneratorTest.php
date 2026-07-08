<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Unit;

use Digit\Bracelets\Domain\Services\BraceletCodeGenerator;
use PHPUnit\Framework\TestCase;

class BraceletCodeGeneratorTest extends TestCase
{
    private BraceletCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BraceletCodeGenerator();
    }

    public function testGeneratedCodeHasValidFormat(): void
    {
        $code = $this->generator->generate();

        $this->assertTrue($this->generator->isValidFormat($code));
        $this->assertStringStartsWith('BR', $code);
    }

    public function testGeneratedCodesAreUniqueAtScale(): void
    {
        // Not a proof of uniqueness at 93,020 (~5x10^20 possible codes makes
        // collisions astronomically unlikely) - just a smoke test that the
        // generator isn't trivially broken (e.g. stuck on a fixed seed).
        $codes = [];

        for ($i = 0; $i < 5000; $i++) {
            $codes[$this->generator->generate()] = true;
        }

        $this->assertCount(5000, $codes);
    }

    public function testCorruptedCodeFailsFormatCheck(): void
    {
        $code = $this->generator->generate();
        $corrupted = substr($code, 0, -1) . (substr($code, -1) === 'A' ? 'B' : 'A');

        $this->assertFalse($this->generator->isValidFormat($corrupted));
    }

    public function testWrongPrefixFailsFormatCheck(): void
    {
        $this->assertFalse($this->generator->isValidFormat('XX12345678901234'));
    }
}
