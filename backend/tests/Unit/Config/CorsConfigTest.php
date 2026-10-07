<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class CorsConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', null);
        parent::tearDown();
    }

    public function test_ipad_kiosk_app_origin_is_always_allowed(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', 'https://ticket.picha.fr');

        $origins = (require config_path('cors.php'))['allowed_origins'];

        self::assertContains('https://ticket.picha.fr', $origins);
        self::assertContains('capacitor://localhost', $origins);
    }

    public function test_wildcard_is_still_never_allowed(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', '*');

        self::assertNotContains('*', (require config_path('cors.php'))['allowed_origins']);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }
        putenv("$key=$value");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
