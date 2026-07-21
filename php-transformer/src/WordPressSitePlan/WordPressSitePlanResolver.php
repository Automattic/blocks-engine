<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/** Resolves declared asset tokens using explicit runtime destination context. */
final class WordPressSitePlanResolver
{
    public const RESOLUTION_SCHEMA = 'blocks-engine/wordpress-site-plan-resolution/v1';
    /** @param array<string,mixed> $plan @param array<string,mixed> $context @return array<string,mixed> */
    public function resolve(array $plan, array $context): array
    {
        WordPressSitePlan::assertValid($plan);
        if (isset($plan['resolution'])) throw new InvalidArgumentException('WordPress site plan is already a resolved projection.');
        if (true === ($context['require_proven_dynamic_client_assets'] ?? false) && 'not_proven' === ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null)) throw new InvalidArgumentException('WordPress site plan cannot prove dynamic client asset references.');
        $themeUri = self::normalizeThemeUri($context['theme_uri'] ?? null);
        $references = self::references($plan['reference_tokens'], $themeUri);
        foreach ($plan['pages'] as &$page) $page['resolved_block_markup'] = self::resolvePayload($page['canonical_block_markup'], $references);
        unset($page);
        foreach ($plan['template_parts'] as &$part) $part['resolved_block_markup'] = self::resolvePayload($part['canonical_block_markup'], $references);
        unset($part);
        foreach ($plan['templates'] as &$template) $template['resolved_block_markup'] = self::resolvePayload($template['canonical_block_markup'], $references);
        unset($template);
        foreach ($plan['writes'] as &$write) if ('utf8' === $write['payload']['encoding']) { $write['canonical_payload'] = $write['payload']['data']; $write['canonical_payload_hash'] = WordPressSitePlan::contentHash($write['canonical_payload']); $write['payload']['data'] = self::resolvePayload($write['canonical_payload'], $references); $write['payload_hash'] = WordPressSitePlan::contentHash($write['payload']['data']); }
        unset($write);
        foreach (array('pages', 'template_parts') as $documents) foreach ($plan[$documents] as &$document) foreach (array('links', 'scripts') as $kind) { if (!is_array($document['document_metadata'][$kind] ?? null)) continue; foreach ($document['document_metadata'][$kind] as &$declaration) if (is_string($declaration['asset_reference'] ?? null)) $declaration['resolved_url'] = self::resolvePayload($declaration['asset_reference'], $references); }
        unset($declaration, $document);
        $plan['resolution'] = array('schema' => self::RESOLUTION_SCHEMA, 'theme_uri' => $themeUri);
        WordPressSitePlan::assertValid($plan);
        return $plan;
    }

    /** @param array<string,string> $references */
    public static function resolvePayload(string $content, array $references): string
    {
        $resolved = strtr($content, $references);
        if (str_contains($resolved, WordPressSitePlan::TOKEN_PREFIX)) throw new InvalidArgumentException('WordPress site plan contains unresolved reference tokens.');
        return $resolved;
    }

    public static function normalizeThemeUri(mixed $value): string
    {
        if (!is_string($value) || '' === $value || preg_match('/[\x00-\x20\x7f]/', $value) || false === ($parts = parse_url($value))) throw new InvalidArgumentException('WordPress site plan resolution requires a valid theme_uri.');
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true) || '' === $parts['host']) throw new InvalidArgumentException('WordPress site plan resolution requires an absolute http(s) theme_uri without credentials, query, or fragment.');
        if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) throw new InvalidArgumentException('WordPress site plan resolution theme_uri has an invalid port.');
        $path = $parts['path'] ?? '';
        if (!is_string($path) || ('' !== $path && !str_starts_with($path, '/')) || str_contains($path, '\\') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|%2f|%5c|%2e~i', $path)) throw new InvalidArgumentException('WordPress site plan resolution theme_uri has an ambiguous path.');
        $authority = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return strtolower($parts['scheme']) . '://' . $authority . rtrim($path, '/');
    }
    /** @param array<int,array<string,mixed>> $tokens @return array<string,string> */
    public static function references(array $tokens, string $themeUri): array { $references = array(); foreach ($tokens as $reference) if (is_array($reference) && is_string($reference['token'] ?? null) && is_string($reference['target_path'] ?? null)) $references['{{wordpress-site-plan:asset:' . $reference['token'] . '}}'] = $themeUri . '/' . $reference['target_path']; return $references; }
}
