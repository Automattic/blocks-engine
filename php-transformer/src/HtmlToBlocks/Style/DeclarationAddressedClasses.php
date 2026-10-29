<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * Final identity of generated control classes, after all support families have
 * contributed their rules. Source-selector markers are provisional bindings,
 * not the identity of the CSS we publish.
 *
 * A declaration address includes the selector surface (wrapper, descendant,
 * pseudo state), enclosing conditions and rule order. Equal base declarations
 * alone must not alias controls with different hover or responsive rules.
 */
final class DeclarationAddressedClasses
{
    private const PATTERN = '/\bblocks-engine-(?:control|native-button)-[a-f0-9]{12}-[0-9]+\b/';

    /** @param list<array<string, mixed>> $stylesheets @return array<string, string> */
    public function replacements(array $stylesheets): array
    {
        $records = array();
        $transformer = new CssStylesheetTransformer();
        foreach ($stylesheets as $stylesheet) {
            $context = array(
                (string) ($stylesheet['stylesheet_placement'] ?? 'author'),
                (string) ($stylesheet['stylesheet_target'] ?? 'both'),
                (string) ($stylesheet['media'] ?? ''),
                (string) ($stylesheet['type'] ?? ''),
            );
            $transformer->visitStyleRules((string) $stylesheet['content'], static function (string $selector, string $body, array $parents) use (&$records, $context): void {
                $declarations = self::declarations($body);
                foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array($selector) as $arm) {
                    preg_match_all(self::PATTERN, $arm, $matches);
                    foreach (array_unique($matches[0]) as $marker) {
                        $surface = str_replace($marker, '__control__', trim($arm));
                        $record = json_encode(array($context, $parents, $surface, $declarations), JSON_THROW_ON_ERROR);
                        $records[$marker][] = $record;
                    }
                }
            });
        }
        $replacements = array();
        foreach ($records as $marker => $rules) {
            // Keep competing rule order, including A/B/A overrides. Sorting
            // or treating these records as an unordered set changes CSS.
            $signature = implode("\n", $rules);
            preg_match('/^blocks-engine-(control|native-button)-/', $marker, $kind);
            $replacements[$marker] = 'blocks-engine-' . $kind[1] . '-' . substr(hash('sha256', $signature), 0, 12) . '-0';
        }
        return $replacements;
    }

    /**
     * Generated stylesheet paths address the published bytes, not provisional
     * control bindings. Include paths in the same rewrite as all their users.
     * @param array<string, array<string, mixed>> $assets
     * @param array<string, string> $replacements
     * @return array<string, string>
     */
    public function assetPathReplacements(array $assets, array $replacements): array
    {
        $paths = array();
        foreach ($assets as $asset) {
            if (!self::isContentAddressedStylesheet($asset)) continue;
            $content = $this->rewrite($asset['content'], $replacements);
            $path = $asset['path'];
            $paths[$path] = substr($path, 0, -20) . substr(hash('sha256', $content), 0, 16) . '.css';
        }
        return $paths;
    }

    /** @param array<string, mixed> $asset */
    private static function isContentAddressedStylesheet(array $asset): bool
    {
        return 'css' === ($asset['kind'] ?? '')
            && '' === ($asset['source_path'] ?? '')
            && is_string($asset['content'] ?? null)
            && str_ends_with((string) ($asset['path'] ?? ''), '-' . substr(hash('sha256', $asset['content']), 0, 16) . '.css');
    }

    /**
     * Normalize declaration syntax, not semantics: preserve fallback and
     * shorthand order, custom-property case, strings and !important.
     * @return list<string>
     */
    private static function declarations(string $body): array
    {
        $result = array();
        $properties = array();
        $ordered = false;
        foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
            $colon = strpos($declaration, ':');
            if (false === $colon) {
                $result[] = $declaration;
                $ordered = true;
                continue;
            }
            $property = trim(substr($declaration, 0, $colon));
            if (!str_starts_with($property, '--')) $property = strtolower($property);
            $normalized = $property . ':' . trim(substr($declaration, $colon + 1));
            $result[] = $normalized;
            // Only these independent longhands are known to commute. Do not
            // sort arbitrary CSS: shorthands, logical sides and fallbacks can
            // override each other even when their property names differ.
            if (!in_array($property, array('color', 'background-color', 'opacity', 'border-radius', 'padding', 'margin'), true) || isset($properties[$property])) $ordered = true;
            $properties[$property] = $normalized;
        }
        if ($ordered) return $result;
        ksort($properties, SORT_STRING);
        return array_values($properties);
    }

    /**
     * Rewrite all compilation facts together: markup, block attributes,
     * provenance, projections and assets must never disagree about identity.
     * @param array<string, string> $replacements
     */
    public function rewrite(mixed $value, array $replacements): mixed
    {
        if (is_string($value)) {
            $value = preg_replace_callback(self::PATTERN, static fn (array $match): string => $replacements[$match[0]] ?? $match[0], $value) ?? $value;
            $paths = array_filter($replacements, static fn (string $key): bool => str_starts_with($key, 'assets/css/'), ARRAY_FILTER_USE_KEY);
            return strtr($value, $paths);
        }
        if (!is_array($value)) return $value;
        $originalContent = $value['content'] ?? null;
        $contentAddressed = self::isContentAddressedStylesheet($value);
        $rewritten = array();
        foreach ($value as $key => $item) {
            $rewritten[is_string($key) ? strtr($key, $replacements) : $key] = $this->rewrite($item, $replacements);
        }
        $value = $rewritten;
        if (is_string($originalContent) && $originalContent !== $value['content']) {
            if (isset($value['bytes'])) $value['bytes'] = strlen($value['content']);
            foreach (array('hash', 'content_hash') as $key) {
                if (isset($value[$key])) $value[$key] = hash('sha256', $value['content']);
            }
            if ($contentAddressed) $value['source_hash'] = hash('sha256', $value['content']);
        }
        return $value;
    }
}
