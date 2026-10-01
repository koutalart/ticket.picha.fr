<?php

namespace Tests\Unit\Helper;

use HiEvents\Helper\CustomDomainHelper;
use Tests\TestCase;

class CustomDomainHelperTest extends TestCase
{
    public function testNormalize(): void
    {
        $this->assertSame('innocent976.yt', CustomDomainHelper::normalize('https://www.Innocent976.YT/path'));
        $this->assertSame('innocent976.yt', CustomDomainHelper::normalize('innocent976.yt:443'));
        $this->assertSame('billets.example.com', CustomDomainHelper::normalize('billets.example.com.'));
        $this->assertNull(CustomDomainHelper::normalize('   '));
        $this->assertNull(CustomDomainHelper::normalize(null));
    }

    public function testIsValid(): void
    {
        $this->assertTrue(CustomDomainHelper::isValid('innocent976.yt'));
        $this->assertTrue(CustomDomainHelper::isValid('billets.mon-asso.fr'));
        $this->assertFalse(CustomDomainHelper::isValid('localhost'));
        $this->assertFalse(CustomDomainHelper::isValid('-bad.fr'));
        $this->assertFalse(CustomDomainHelper::isValid('bad-.fr'));
        $this->assertFalse(CustomDomainHelper::isValid('a b.fr'));
    }
}
