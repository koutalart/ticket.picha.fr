<?php

namespace Tests\Unit\Helper;

use HiEvents\Helper\SmsKeywordDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsKeywordDetectorTest extends TestCase
{
    public static function keywordProvider(): array
    {
        return [
            ['STOP', SmsKeywordDetector::STOP],
            ['stop', SmsKeywordDetector::STOP],
            [' Stop. ', SmsKeywordDetector::STOP],
            ['STOP SMS', SmsKeywordDetector::STOP],
            ['Arrêt', SmsKeywordDetector::STOP],
            ['ARRET', SmsKeywordDetector::STOP],
            ['start', SmsKeywordDetector::START],
            ['Je viens samedi, stop les embrouilles', null],
            ['Combien coûte la place ?', null],
            ['', null],
        ];
    }

    #[DataProvider('keywordProvider')]
    public function test_detect(string $content, ?string $expected): void
    {
        $this->assertSame($expected, SmsKeywordDetector::detect($content));
    }
}
