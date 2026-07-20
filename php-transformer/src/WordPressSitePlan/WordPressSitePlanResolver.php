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
        $themeUri = $context['theme_uri'] ?? null;
        if (!is_string($themeUri) || !filter_var($themeUri, FILTER_VALIDATE_URL) || !in_array(parse_url($themeUri, PHP_URL_SCHEME), array('http', 'https'), true)) {
            throw new InvalidArgumentException('WordPress site plan resolution requires an absolute http(s) theme_uri.');
        }
        $themeUri = rtrim($themeUri, '/');
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
}
