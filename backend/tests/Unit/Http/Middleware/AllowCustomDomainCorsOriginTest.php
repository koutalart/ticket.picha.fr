<?php

namespace Tests\Unit\Http\Middleware;

use HiEvents\Http\Middleware\AllowCustomDomainCorsOrigin;
use HiEvents\Services\Infrastructure\Cors\CustomDomainCorsOriginResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Tests\TestCase;

class AllowCustomDomainCorsOriginTest extends TestCase
{
    private CustomDomainCorsOriginResolver $resolver;
    private AllowCustomDomainCorsOrigin $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cors.allowed_origins' => ['https://ticket.picha.fr']]);
        $this->resolver = Mockery::mock(CustomDomainCorsOriginResolver::class);
        $this->middleware = new AllowCustomDomainCorsOrigin($this->resolver, $this->app['config']);
    }

    public function testAddsRegisteredCustomDomainOrigin(): void
    {
        $this->resolver->shouldReceive('isAllowedOrigin')->with('https://innocent976.yt')->andReturn(true);

        $this->handle('https://innocent976.yt');

        $this->assertSame(['https://ticket.picha.fr', 'https://innocent976.yt'], config('cors.allowed_origins'));
    }

    public function testDoesNotAddUnknownOrigin(): void
    {
        $this->resolver->shouldReceive('isAllowedOrigin')->with('https://audit.example')->andReturn(false);

        $this->handle('https://audit.example');

        $this->assertSame(['https://ticket.picha.fr'], config('cors.allowed_origins'));
    }

    public function testSkipsLookupForPlatformOriginAndMissingOrigin(): void
    {
        $this->resolver->shouldNotReceive('isAllowedOrigin');

        $this->handle('https://ticket.picha.fr');
        $this->handle(null);

        $this->assertSame(['https://ticket.picha.fr'], config('cors.allowed_origins'));
    }

    private function handle(?string $origin): void
    {
        $request = Request::create('/users/me');
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }

        $this->middleware->handle($request, fn() => new Response());
    }
}
