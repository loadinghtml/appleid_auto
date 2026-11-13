<?php
declare (strict_types=1);

namespace app\service;

use InvalidArgumentException;

class ShareOriginPolicy
{
    public static function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $origin)) {
            return null;
        }
        $parts = parse_url($origin);
        if (!$parts || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '/')) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['https', 'http'], true)
            || (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP))) {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        return $scheme . '://' . $host . ($defaultPort ? '' : ':' . $port);
    }

    public static function normalizeWhitelist($input): string
    {
        if (!is_string($input) || strlen($input) > 8192) {
            throw new InvalidArgumentException('API域名白名单格式错误或内容过长');
        }
        $origins = [];
        foreach (preg_split('/[\r\n,]+/', $input) as $entry) {
            if (trim($entry) === '') {
                continue;
            }
            $origin = self::normalizeOrigin($entry);
            if ($origin === null) {
                throw new InvalidArgumentException('白名单请填写完整的HTTP或HTTPS来源，不含路径、通配符和账号信息');
            }
            $origins[$origin] = true;
        }
        return implode("\n", array_keys($origins));
    }

    public static function allows($whitelist, string $origin): bool
    {
        if ($origin !== trim($origin)) {
            return false;
        }
        $normalized = self::normalizeOrigin($origin);
        if ($normalized === null) {
            return false;
        }
        try {
            $allowed = self::normalizeWhitelist($whitelist ?? '');
        } catch (InvalidArgumentException $e) {
            return false;
        }
        return $allowed !== '' && in_array($normalized, explode("\n", $allowed), true);
    }
}
