<?php

namespace HiEvents\Http\Middleware;

use Closure;
use HiEvents\Services\Infrastructure\Cors\CustomDomainCorsOriginResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowCustomDomainCorsOrigin
{
    public function __construct(
        private readonly CustomDomainCorsOriginResolver $resolver,
        private readonly ConfigRepository               $config,
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigins = (array)$this->config->get('cors.allowed_origins', []);

        if ($origin !== null && !in_array($origin, $allowedOrigins, true) && $this->resolver->isAllowedOrigin($origin)) {
            $this->config->set('cors.allowed_origins', [...$allowedOrigins, $origin]);
        }

        return $next($request);
    }
}
