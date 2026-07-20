<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/** Resolves declared asset tokens using explicit runtime destination context. */
final class WordPressSitePlanResolver
{
    /** @param array<string,mixed> $plan @param array<string,mixed> $context @return array<string,mixed> */
    public function resolve(array $plan, array $context): array
    {
        WordPressSitePlan::assertValid($plan);
        $themeUri = self::themeUri($context['theme_uri'] ?? null);
        $references = array();
        foreach ($plan['reference_tokens'] as $reference) $references['{{wordpress-site-plan:asset:' . $reference['token'] . '}}'] = $themeUri . '/' . $reference['target_path'];
        foreach ($plan['pages'] as &$page) $page['resolved_block_markup'] = self::replace($page['canonical_block_markup'], $references);
        unset($page);
        foreach ($plan['template_parts'] as &$part) $part['resolved_block_markup'] = self::replace($part['canonical_block_markup'], $references);
        unset($part);
        foreach ($plan['templates'] as &$template) $template['resolved_block_markup'] = self::replace($template['canonical_block_markup'], $references);
        unset($template);
        foreach ($plan['writes'] as &$write) if ('utf8' === $write['payload']['encoding']) $write['payload']['data'] = self::replace($write['payload']['data'], $references);
        unset($write);
        $plan['resolution'] = array('theme_uri' => $themeUri);
        return $plan;
    }

    /** @param array<string,string> $references */
    private static function replace(string $content, array $references): string
    {
        $resolved = strtr($content, $references);
        if (str_contains($resolved, WordPressSitePlan::TOKEN_PREFIX)) throw new InvalidArgumentException('WordPress site plan contains unresolved reference tokens.');
        return $resolved;
    }

    private static function themeUri(mixed $value): string
    {
        if (!is_string($value) || '' === $value || preg_match('/[\x00-\x20\x7f]/', $value) || false === ($parts = parse_url($value))) throw new InvalidArgumentException('WordPress site plan resolution requires a valid theme_uri.');
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true) || '' === $parts['host']) throw new InvalidArgumentException('WordPress site plan resolution requires an absolute http(s) theme_uri without credentials, query, or fragment.');
        if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) throw new InvalidArgumentException('WordPress site plan resolution theme_uri has an invalid port.');
        $path = $parts['path'] ?? '';
        if (!is_string($path) || ('' !== $path && !str_starts_with($path, '/')) || str_contains($path, '\\') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|%2f|%5c|%2e~i', $path)) throw new InvalidArgumentException('WordPress site plan resolution theme_uri has an ambiguous path.');
        $authority = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return strtolower($parts['scheme']) . '://' . $authority . rtrim($path, '/');
    }
}
