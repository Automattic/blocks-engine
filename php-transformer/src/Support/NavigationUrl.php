<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** URL identity for absolute navigation destinations, after route mapping. */
final class NavigationUrl
{
    public static function serialize(string $url): string
    {
        if (!preg_match('~^([a-z][a-z0-9+.-]*):(?://([^/?#]*))?(.*)$~is', $url, $match)) return $url;
        $scheme = strtolower($match[1]);
        $defaults = array('ftp' => 21, 'http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443);
        $special = 'file' === $scheme || isset($defaults[$scheme]);
        // Opaque destinations (mailto:, tel:, etc.) have no host or special path.
        if (!str_starts_with(substr($url, strlen($match[1]) + 1), '//')) return $scheme . ':' . $match[3];
        $parts = parse_url($url);
        if (false === $parts || (!isset($parts['host']) && 'file' !== $scheme)) return $url;
        $authority = '';
        if (isset($parts['user'])) {
            $authority = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $authority .= strtolower($parts['host'] ?? '');
        if (isset($parts['port']) && $parts['port'] !== ($defaults[$scheme] ?? null)) $authority .= ':' . $parts['port'];
        // Preserve authored path bytes, query and fragment, including empty ?/#.
        $suffix = $match[3];
        if ($special && ('' === $suffix || '?' === $suffix[0] || '#' === $suffix[0])) $suffix = '/' . $suffix;
        return $scheme . '://' . $authority . $suffix;
    }
}
