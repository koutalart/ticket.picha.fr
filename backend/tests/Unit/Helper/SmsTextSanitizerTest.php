<?php

namespace Tests\Unit\Helper;

use HiEvents\Helper\SmsTextSanitizer;
use Tests\TestCase;

class SmsTextSanitizerTest extends TestCase
{
    public function test_removes_html_tags_but_keeps_plain_symbols(): void
    {
        $this->assertSame(
            "Bonjour {prenom}, merci <3\nA bientôt",
            SmsTextSanitizer::clean("  <b>Bonjour</b> {prenom}, merci <3\r\nA bientôt<script>x</script>  "),
        );
    }

    public function test_removes_control_characters(): void
    {
        $this->assertSame("Salut\tà toi 🎃", SmsTextSanitizer::clean("Salut\tà\x00 toi\x07 🎃"));
    }
}
