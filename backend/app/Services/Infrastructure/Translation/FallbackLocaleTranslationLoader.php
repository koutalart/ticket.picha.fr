<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Translation;

use Illuminate\Contracts\Translation\Loader;

/**
 * Lets a variant locale (fr_TU) hold only the lines that differ from its parent (fr):
 * every other line is read from the parent instead of the application fallback locale.
 */
class FallbackLocaleTranslationLoader implements Loader
{
    /**
     * @param  array<string, string>  $parents  variant locale => parent locale
     */
    public function __construct(
        private readonly Loader $loader,
        private readonly array $parents,
    ) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->loader->load($locale, $group, $namespace);
        $parent = $this->parents[$locale] ?? null;

        if ($parent === null) {
            return $lines;
        }

        return array_replace_recursive($this->loader->load($parent, $group, $namespace), $lines);
    }

    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->loader->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->loader->namespaces();
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->loader->{$method}(...$arguments);
    }
}
