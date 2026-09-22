<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/** Projects source CSS that has an exact Global Styles equivalent. */
final class ThemeJsonProjection
{
    /**
     * Source typography is routinely applied through custom properties
     * (`h1,h2{font-family:var(--font-serif)}` defined by
     * `:root{--font-serif:"Cormorant Garamond",serif}`), including class-scoped
     * utilities (`.font-mono{font-family:var(--font-mono)}`). Those declarations
     * are resolved before representability so every distinct used stack reaches
     * settings.typography.fontFamilies; only global element selectors also
     * project into styles.typography. Every other property keeps its
     * literal-only representability because a resolved color or spacing token
     * still cannot prove cascade independence.
     *
     * @var list<string>
     */
    private const VARIABLE_REFERENCED_PROPERTIES = array('font-family', 'font-size', 'line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style');

    /**
     * A CSS-wide keyword describes cascade resolution, not a design token, so it
     * may carry a style value but never becomes an editor-facing preset.
     *
     * @var list<string>
     */
    private const CSS_WIDE_KEYWORDS = array('inherit', 'initial', 'revert', 'revert-layer', 'unset');

    /**
     * Only global element selectors are removed from carrier CSS. Class, state,
     * and responsive selectors remain authored CSS because theme.json cannot
     * reproduce their cascade semantics.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array{assets:array<int,array<string,mixed>>,theme:array<string,mixed>,provenance:array<int,array<string,mixed>>,presets:array<string,array<string,string>>}
     */
    public function project(array $assets): array
    {
        $variables = $this->customProperties($assets);
        $candidates = array();
        $conditionalProperties = array();
        $unrepresentableProperties = array();
        $fontFamilyStacks = array();
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $assetIndex => $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $path = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
            $hash = (string) ($asset['content_hash'] ?? $asset['hash'] ?? hash('sha256', $asset['content']));
            $visitor->visitStyleRules($asset['content'], function (string $prelude, string $body, array $ancestors) use (&$candidates, &$conditionalProperties, &$unrepresentableProperties, &$fontFamilyStacks, $assetIndex, $path, $hash, $variables): void {
                // Cascade layers qualify where a declaration sits in the source
                // cascade but leave it unconditional; media, supports, container,
                // scope, and starting-style ancestors make it cascade-conditional.
                $layer = array();
                $conditional = false;
                foreach ($ancestors as $ancestor) {
                    if (1 === preg_match('/^@layer(?:\s|$)/', $ancestor)) { $layer[] = $ancestor; continue; }
                    $conditional = true;
                    break;
                }
                $resolved = array();
                foreach ($this->declarations($body) as $name => $value) {
                    if (in_array($name, self::VARIABLE_REFERENCED_PROPERTIES, true)) $value = $this->resolveVariableReferences($value, $variables);
                    $resolved[$name] = $value;
                    if ('font-family' === $name && $this->representable('body', 'font-family', $value) && !in_array(strtolower($value), self::CSS_WIDE_KEYWORDS, true)) $fontFamilyStacks[$value] = true;
                }
                $targets = $this->targets($prelude);
                if (null === $targets) return;
                // Global Styles cannot reproduce a declaration that source CSS varies
                // inside a conditional cascade, so keep that property source-owned.
                if ($conditional) {
                    foreach ($resolved as $name => $value) {
                        foreach ($targets as $target) {
                            $key = $target . "\n" . $name;
                            if ($this->representable($target, $name, $value)) $conditionalProperties[$key] = true;
                            else $unrepresentableProperties[$key] = true;
                        }
                    }
                    return;
                }
                foreach ($resolved as $name => $value) {
                    // A CSS-wide keyword inside a cascade layer defers to whatever
                    // else the cascade supplies; a reset layer states `inherit` so
                    // a later layer can win. Global Styles is unlayered, so
                    // projecting that deferral would outrank every author layer and
                    // invert the source cascade — turning "defer" into "override".
                    // The deferral stays source-owned.
                    if (array() !== $layer && in_array(strtolower($value), self::CSS_WIDE_KEYWORDS, true)) continue;
                    foreach ($targets as $target) {
                        if (!$this->representable($target, $name, $value)) {
                            $unrepresentableProperties[$target . "\n" . $name] = true;
                            continue;
                        }
                        $candidates[] = array('asset' => $assetIndex, 'path' => $path, 'hash' => $hash, 'selector' => strtolower(trim($prelude)), 'target' => $target, 'property' => $name, 'value' => $value, 'layer' => implode('>', $layer));
                    }
                }
            });
        }

        // A target and property declared under more than one cascade layer has a
        // winner decided by layer priority, which theme.json cannot reproduce, so
        // the property stays source-owned instead of projecting one contender.
        $layers = array();
        foreach ($candidates as $candidate) $layers[$candidate['target'] . "\n" . $candidate['property']][$candidate['layer']] = true;
        $candidates = array_values(array_filter($candidates, static fn(array $candidate): bool => 1 === count($layers[$candidate['target'] . "\n" . $candidate['property']])));

        $counts = array_count_values(array_map(static fn(array $candidate): string => $candidate['property'] . "\n" . strtolower($candidate['value']), $candidates));
        $selected = array_values(array_filter($candidates, static fn(array $candidate): bool => !isset($conditionalProperties[$candidate['target'] . "\n" . $candidate['property']]) && !isset($unrepresentableProperties[$candidate['target'] . "\n" . $candidate['property']]) && (1 < $counts[$candidate['property'] . "\n" . strtolower($candidate['value'])] || 'body' === $candidate['target'] || 'layout' === $candidate['target'] || str_starts_with($candidate['target'], 'element:'))));
        $presets = $this->presets($selected, $fontFamilyStacks);

        return array('assets' => $assets, 'theme' => $this->theme($selected, $presets, $this->fontFaces($assets)), 'provenance' => array_values(array_map(static fn(array $candidate): array => array('source_path' => $candidate['path'], 'source_hash' => $candidate['hash'], 'selector' => $candidate['selector'], 'property' => $candidate['property'], 'value' => $candidate['value']), $selected)), 'presets' => $presets);
    }

    /**
     * One theme.json target per source selector, or null when the rule targets
     * none. Comma-separated global rules (`h1,h2,h3,h4,h5,h6{…}`) project onto
     * every element they style; a malformed selector list falls back to a single
     * exact-match attempt.
     *
     * @return array<int,string>|null
     */
    private function targets(string $prelude): ?array
    {
        $selectors = CssStylesheetTransformer::splitSelectorList(strtolower(trim($prelude))) ?? array(strtolower(trim($prelude)));
        $targets = array();
        foreach ($selectors as $selector) {
            $target = $this->target(trim($selector));
            if (null !== $target) $targets[] = $target;
        }
        return array() === $targets ? null : array_values(array_unique($targets));
    }

    /**
     * Custom-property declarations across every projected stylesheet. Source
     * typography resolves its typefaces through them, and the cascade they
     * depend on is resolved before a value may be projected.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,string>
     */
    private function customProperties(array $assets): array
    {
        $variables = array();
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $visitor->visitStyleRules($asset['content'], static function (string $prelude, string $body) use (&$variables): void {
                if (str_starts_with(ltrim($prelude), '@') || !preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER)) return;
                foreach ($matches as $match) $variables[(string) $match[1]] = trim((string) $match[2]);
            });
        }
        return $variables;
    }

    /**
     * Expand `var(--name[, fallback])` references against the source custom
     * properties. Bounded passes resolve variables that reference other
     * variables; an unresolvable reference stays in the value and the value is
     * rejected by representability, never projected as a literal var() token.
     *
     * @param array<string,string> $variables
     */
    private function resolveVariableReferences(string $value, array $variables): string
    {
        for ($pass = 0; $pass < 5 && str_contains($value, 'var('); $pass++) {
            $expanded = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/', static function (array $match) use ($variables): string {
                if (isset($variables[(string) $match[1]]) && '' !== $variables[(string) $match[1]]) return $variables[(string) $match[1]];
                return isset($match[2]) && '' !== trim((string) $match[2]) ? trim((string) $match[2]) : (string) $match[0];
            }, $value);
            if (null === $expanded || $expanded === $value) break;
            $value = $expanded;
        }
        return $value;
    }

    /**
     * Typed @font-face records whose source file the plan already materializes.
     * Only faces backed by a font asset survive; remote or data URLs stay
     * authored-CSS owned because theme.json cannot carry their payload.
     *
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private function fontFaces(array $assets): array
    {
        $materialized = array();
        foreach ($assets as $asset) {
            if (!is_array($asset) || !str_starts_with(strtolower((string) ($asset['mime_type'] ?? '')), 'font/')) continue;
            $sourcePath = (string) ($asset['source_path'] ?? '');
            $targetPath = (string) ($asset['target_path'] ?? '');
            if ('' === $sourcePath || '' === $targetPath) continue;
            $materialized[$sourcePath] = $targetPath;
        }
        if (array() === $materialized) return array();
        $faces = array();
        $seen = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || !preg_match_all('/@font-face\s*\{([^{}]{1,16384})\}/i', $asset['content'], $matches)) continue;
            $cssPath = (string) ($asset['source_path'] ?? $asset['path'] ?? '');
            foreach ($matches[1] as $body) {
                $properties = array();
                foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
                    $parts = explode(':', $declaration, 2);
                    if (2 === count($parts)) $properties[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                $family = isset($properties['font-family']) ? trim($properties['font-family'], " \t\n\r\0\x0B\"'") : '';
                if ('' === $family || !isset($properties['src'])) continue;
                $src = null;
                if (preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $properties['src'], $urls)) {
                    foreach ($urls[2] as $url) {
                        $target = $this->materializedFontTarget((string) $url, $cssPath, $materialized);
                        if (null !== $target) { $src = 'file:./' . $target; break; }
                    }
                }
                if (null === $src) continue;
                $style = isset($properties['font-style']) && '' !== $properties['font-style'] ? $properties['font-style'] : null;
                $weight = isset($properties['font-weight']) && '' !== $properties['font-weight'] ? $properties['font-weight'] : null;
                $key = strtolower($family) . "\n" . (string) $style . "\n" . (string) $weight . "\n" . $src;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $face = array('family' => $family, 'src' => $src);
                if (null !== $style) $face['fontStyle'] = $style;
                if (null !== $weight) $face['fontWeight'] = $weight;
                $faces[] = $face;
            }
        }
        return $faces;
    }

    /** @param array<string,string> $materialized */
    private function materializedFontTarget(string $url, string $cssPath, array $materialized): ?string
    {
        foreach (array(ArtifactPath::resolveRelativePath($url, $cssPath), ltrim($url, '/')) as $candidate) {
            if ('' === $candidate) continue;
            if (isset($materialized[$candidate])) return $materialized[$candidate];
            foreach ($materialized as $sourcePath => $targetPath) {
                if (str_ends_with('/' . $sourcePath, '/' . $candidate) || str_ends_with('/' . $candidate, '/' . $sourcePath)) return $targetPath;
            }
        }
        return null;
    }

    /** @return array<string,string>|null */
    private function target(string $selector): ?string
    {
        if ('body' === $selector) return 'body';
        if ('main' === $selector) return 'layout';
        if (preg_match('/^h([1-6])$/', $selector, $match)) return 'element:h' . $match[1];
        return in_array($selector, array('a', 'button'), true) ? 'element:' . $selector : null;
    }

    /** @return array<string,string> */
    private function declarations(string $body): array
    {
        $declarations = array();
        foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            $name = strtolower(trim($parts[0])); $value = trim($parts[1]);
            if ('' !== $name && '' !== $value && !str_contains(strtolower($value), '!important')) $declarations[$name] = $value;
        }
        return $declarations;
    }

    private function representable(string $target, string $property, string $value): bool
    {
        if (str_contains($value, 'var(') || str_contains($value, 'calc(')) return false;
        if (in_array($property, array('color', 'background-color'), true)) return (bool) preg_match('/^(?:#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch)\([^;]+\)|[a-z]+)$/i', $value);
        if (in_array($property, array('font-family', 'font-size', 'line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), true)) return true;
        if ('body' === $target && in_array($property, array('padding', 'margin', 'gap'), true)) return (bool) preg_match('/^[0-9.]+(?:px|rem|em|%|vw|vh)$/i', $value);
        if ('layout' === $target && 'max-width' === $property) return (bool) preg_match('/^[0-9.]+(?:px|rem|em|%|vw|vh)$/i', $value);
        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $selected
     * @param array<string,true> $fontFamilyStacks
     * @return array<string,array<string,string>>
     */
    private function presets(array $selected, array $fontFamilyStacks = array()): array
    {
        $presets = array('color' => array(), 'font-family' => array(), 'font-size' => array(), 'spacing' => array());
        foreach ($selected as $candidate) {
            $group = match ($candidate['property']) {
                'color', 'background-color' => 'color', 'font-family' => 'font-family', 'font-size' => 'font-size', 'padding', 'margin', 'gap' => 'spacing', default => '',
            };
            if ('' !== $group && !in_array(strtolower($candidate['value']), self::CSS_WIDE_KEYWORDS, true)) $presets[$group][$candidate['value']] = $this->slug($group, $candidate['value']);
        }
        foreach (array_keys($fontFamilyStacks) as $stack) {
            if (is_string($stack) && '' !== $stack) $presets['font-family'][$stack] = $this->slug('font-family', $stack);
        }
        foreach ($presets as &$values) ksort($values, SORT_STRING); unset($values);
        return $presets;
    }

    /** @param array<int,array<string,mixed>> $selected @param array<string,array<string,string>> $presets @param array<int,array<string,mixed>> $faces @return array<string,mixed> */
    private function theme(array $selected, array $presets, array $faces = array()): array
    {
        $settings = array();
        if (array() !== $presets['color']) $settings['color']['palette'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'color' => $value), array_keys($presets['color']), $presets['color']);
        if (array() !== $presets['font-family']) {
            $families = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'fontFamily' => $value), array_keys($presets['font-family']), $presets['font-family']);
            foreach ($families as $index => $family) {
                $stack = (string) $family['fontFamily'];
                foreach (explode(',', $stack) as $token) {
                    $token = trim($token, " \t\n\r\0\x0B\"'");
                    if ('' !== $token && 1 === preg_match('/^[A-Za-z][A-Za-z0-9 _.\'-]*$/', $token)) { $families[$index]['name'] = $token; break; }
                }
                $familyFaces = array_values(array_filter($faces, static fn(array $face): bool => self::stackReferencesFamily($stack, (string) ($face['family'] ?? ''))));
                if (array() !== $familyFaces) $families[$index]['fontFace'] = $familyFaces;
            }
            $settings['typography']['fontFamilies'] = $families;
        }
        if (array() !== $presets['font-size']) $settings['typography']['fontSizes'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'size' => $value), array_keys($presets['font-size']), $presets['font-size']);
        if (array() !== $presets['spacing']) $settings['spacing']['spacingSizes'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $slug, 'size' => $value), array_keys($presets['spacing']), $presets['spacing']);
        $styles = array();
        foreach ($selected as $candidate) {
            $target = $candidate['target']; $property = $candidate['property']; $value = $candidate['value'];
            if ('layout' === $target) { $settings['layout']['contentSize'] = $value; continue; }
            $destination = 'body' === $target ? $styles : ($styles['elements'][substr($target, 8)] ?? array());
            if ('color' === $property) $destination['color']['text'] = $this->presetValue($presets['color'], $value, 'color');
            elseif ('background-color' === $property) $destination['color']['background'] = $this->presetValue($presets['color'], $value, 'color');
            elseif ('font-family' === $property) $destination['typography']['fontFamily'] = $this->presetValue($presets['font-family'], $value, 'font-family');
            elseif ('font-size' === $property) $destination['typography']['fontSize'] = $this->presetValue($presets['font-size'], $value, 'font-size');
            elseif (in_array($property, array('line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), true)) $destination['typography'][str_replace(array('line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), array('lineHeight', 'fontWeight', 'letterSpacing', 'textTransform', 'fontStyle'), $property)] = $value;
            elseif ('body' === $target) $destination['spacing'][('gap' === $property ? 'blockGap' : $property)] = $this->presetValue($presets['spacing'], $value, 'spacing');
            if ('body' === $target) $styles = $destination; else $styles['elements'][substr($target, 8)] = $destination;
        }
        // Authored CSS owns every gap in a materialized static site. Without an
        // explicit blockGap the block editor inherits WordPress's default 24px
        // layout gap (`:root :where(.is-layout-flow) > *`), which the frontend
        // never emits, so the same markup renders taller in the editor canvas.
        // The explicit boolean false (not a length) is load-bearing: WordPress
        // core only emits layout gap CSS when
        // `isset( $this->theme_json['settings']['spacing']['blockGap'] )`
        // (WP_Theme_JSON::get_layout_styles() and
        // wp_render_layout_support_flag()), and without the setting it falls
        // back to a 0.5em gap, silently discarding every per-block
        // style.spacing.blockGap value the blocks already carry. A string
        // styles value would additionally make core serialize
        // `:root :where(.is-layout-flow) > *` margin rules at 0-1-0
        // specificity that clobber authored element-level child spacing, so
        // the styles default stays a false that suppresses global gap output
        // while the settings opt-in below keeps per-block gap serialization
        // and the editor gap control enabled.
        if (!isset($styles['spacing']['blockGap'])) $styles['spacing']['blockGap'] = false;
        $settings['spacing']['blockGap'] = true;
        return array('version' => 3, 'settings' => $settings, 'styles' => $styles);
    }

    /** @param array<string,string> $presets */
    private function presetValue(array $presets, string $value, string $type): string { return isset($presets[$value]) ? 'var:preset|' . $type . '|' . $presets[$value] : $value; }
    /** Whether a font-family stack names a typeface, quote- and case-insensitively. */
    private static function stackReferencesFamily(string $stack, string $family): bool
    {
        if ('' === $family) return false;
        foreach (explode(',', $stack) as $token) {
            if (0 === strcasecmp(trim($token, " \t\n\r\0\x0B\"'"), $family)) return true;
        }
        return false;
    }
    private function slug(string $group, string $value): string
    {
        $digest = substr(hash('sha256', strtolower($value)), 0, 10);
        return $group . '-' . (preg_replace('/(?<=\d)(?=[a-f])|(?<=[a-f])(?=\d)/', '-', $digest) ?? $digest);
    }
}
