<?php

namespace HiEvents\Helper;

class CustomDomainHelper
{
    private const DOMAIN_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public static function normalize(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^[a-z]+://#', '', $domain);
        $domain = preg_replace('#[/?\#].*$#', '', $domain);
        $domain = preg_replace('#:\d+$#', '', $domain);
        $domain = rtrim($domain, '.');
        $domain = preg_replace('#^www\.#', '', $domain);

        return $domain === '' ? null : $domain;
    }

    public static function isValid(string $domain): bool
    {
        return (bool)preg_match(self::DOMAIN_PATTERN, $domain);
    }
}
