<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Support;

use Digit\Bracelets\Domain\Services\BraceletCodeGenerator;

/**
 * Test double returning a scripted sequence of codes before falling back
 * to real random generation - used to deterministically force a collision
 * in BraceletGenerationServiceTest instead of relying on chance.
 */
class FakeSequentialCodeGenerator extends BraceletCodeGenerator
{
    private int $index = 0;

    public function __construct(private readonly array $scripted)
    {
    }

    public function generate(): string
    {
        if ($this->index < count($this->scripted)) {
            return $this->scripted[$this->index++];
        }

        return parent::generate();
    }
}
