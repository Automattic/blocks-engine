<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssPropertyName;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;

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
     * Source CSS remains intact. Global element defaults project only when
     * their applicability and values agree across the document inventory;
     * class, state, and responsive selectors remain source-owned.
     *
     * @param array<int,array<string,mixed>> $assets
     * With a usage-derived design system, presets are its role-named tokens
     * instead of every literal the stylesheets mention, and root and element
     * styles the documents render unanimously are projected as preset
     * references (see DesignSystem). Without one, presets are the hashed
     * literals of the projected selector-gated declarations.
     *
     * @param list<string> $documentPaths All documents to which site-wide styles apply.
     * @return array{assets:array<int,array<string,mixed>>,theme:array<string,mixed>,provenance:array<int,array<string,mixed>>,presets:array<string,array<string,string>>,responsive_breakpoints:array<string,mixed>|null}
     */
    public function project(array $assets, array $documentPaths = array(), ?DesignSystem $system = null): array
    {
        $deliveryAssets = $assets;
        // A selectable set owns its declarations for its entire lifetime.
        // Projecting the initially preferred set into permanent Global Styles
        // would keep its values active after the source selects another set.
        $assets = array_filter($assets, static fn(array $asset): bool => StylesheetActivation::active($asset) && '' === ($asset['stylesheet_activation']['title'] ?? ''));
        $variables = $this->customProperties($assets, $documentPaths);
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
                    if (in_array($name, self::VARIABLE_REFERENCED_PROPERTIES, true) || in_array($name, array('color', 'background-color'), true)) $value = $this->resolveVariableReferences($value, $variables);
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
        $presets = null === $system ? $this->presets($selected, $fontFamilyStacks) : $this->systemPresets($system);
        $selected = $this->sharedDefaults($selected, $assets, $documentPaths);
        $responsiveBreakpoints = ResponsiveBreakpoints::fromAssets($assets);
        $theme = $this->theme($selected, $presets, $this->fontFaces($assets), $responsiveBreakpoints['viewport'], $system);
        if (null !== $system) $theme = $this->withSystem($theme, $system, $presets, $this->authoredElementProperties($deliveryAssets, $variables));

        return array('assets' => $deliveryAssets, 'theme' => $theme, 'provenance' => array_values(array_map(static fn(array $candidate): array => array('source_path' => $candidate['path'], 'source_hash' => $candidate['hash'], 'selector' => $candidate['selector'], 'property' => $candidate['property'], 'value' => $candidate['value']), $selected)), 'presets' => $presets, 'responsive_breakpoints' => $responsiveBreakpoints['report']);
    }

    /**
     * Global Styles applies to every document, even those that never load a
     * candidate's stylesheet. Only unanimous values with complete applicability
     * can be promoted. Conflicting contenders stay in source CSS: asset order
     * alone cannot prove the winner of a document's specificity/layer cascade.
     * Presets are collected before this gate because they do not apply styles.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param array<int,array<string,mixed>> $assets
     * @param list<string> $documentPaths
     * @return array<int,array<string,mixed>>
     */
    private function sharedDefaults(array $candidates, array $assets, array $documentPaths): array
    {
        $evidence = array();
        foreach ($candidates as $candidate) {
            $key = $candidate['target'] . "\n" . $candidate['property'];
            $evidence[$key]['values'][$candidate['value']] = true;
            // Unscoped callers supply one stylesheet context; site plans always
            // supply explicit scopes and their complete document inventory.
            foreach ($assets[$candidate['asset']]['scopes'] ?? array(array('kind' => 'global')) as $scope) {
                if ('global' === ($scope['kind'] ?? null)) $evidence[$key]['global'] = true;
                elseif (is_string($scope['source_path'] ?? null)) $evidence[$key]['documents'][$scope['source_path']] = true;
            }
        }
        return array_values(array_filter($candidates, static function (array $candidate) use ($evidence, $documentPaths): bool {
            $entry = $evidence[$candidate['target'] . "\n" . $candidate['property']];
            if (1 !== count($entry['values'])) return false;
            if (!empty($entry['global'])) return true;
            if (array() === $documentPaths) return false;
            foreach ($documentPaths as $path) if (!isset($entry['documents'][$path])) return false;
            return true;
        }));
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
     * @param list<string> $documentPaths
     * @return array<string,string>
     */
    private function customProperties(array $assets, array $documentPaths): array
    {
        $variables = array();
        $conflicts = array();
        $coverage = array();
        $scoped = array();
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $visitor->visitStyleRules($asset['content'], static function (string $prelude, string $body, array $ancestors) use (&$variables, &$conflicts, &$coverage, &$scoped, $asset): void {
                if (str_starts_with(ltrim($prelude), '@') || !preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER)) return;
                foreach ($matches as $match) {
                    $name = (string) $match[1];
                    $value = trim((string) $match[2]);
                    // Identical scoped copies (including editor mirrors) do
                    // not change a root value. Different copies still veto it.
                    if (!in_array(strtolower(trim($prelude)), array(':root', 'html'), true)) {
                        $scoped[$name][$value] = true;
                        continue;
                    }
                    if (isset($variables[$name]) && $variables[$name] !== $value) $conflicts[$name] = true;
                    $variables[$name] = $value;
                    foreach ($ancestors as $ancestor) if (1 !== preg_match('/^@layer(?:\s|$)/', $ancestor)) $conflicts[$name] = true;
                    foreach ($asset['scopes'] ?? array(array('kind' => 'global')) as $scope) {
                        if ('global' === ($scope['kind'] ?? null)) $coverage[$name]['global'] = true;
                        elseif (is_string($scope['source_path'] ?? null)) $coverage[$name]['documents'][$scope['source_path']] = true;
                    }
                }
            });
        }
        foreach ($variables as $name => $value) {
            foreach ($scoped[$name] ?? array() as $other => $_) if ($other !== $value) $conflicts[$name] = true;
            if (!empty($coverage[$name]['global'])) continue;
            if (array() === $documentPaths) $conflicts[$name] = true;
            foreach ($documentPaths as $path) if (!isset($coverage[$name]['documents'][$path])) $conflicts[$name] = true;
        }
        foreach ($scoped as $name => $_) if (!isset($variables[$name])) $conflicts[$name] = true;
        // A route-dependent variable is unresolved, including when a use has a
        // fallback: that fallback does not win where the variable is defined.
        foreach ($conflicts as $name => $_) $variables[$name] = 'var(' . $name . ')';
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
                    if (2 === count($parts) && !CssPropertyName::startsLikeNumber(trim($parts[0]))) $properties[strtolower(trim($parts[0]))] = trim($parts[1]);
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
                $range = trim((string) ($properties['unicode-range'] ?? ''));
                $range = strlen($range) <= 1024 && preg_match('/^U\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?(?:\s*,\s*U\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?)*$/i', $range)
                    ? strtoupper(preg_replace('/\s*,\s*/', ', ', $range) ?? $range)
                    : null;
                $key = strtolower($family) . "\n" . (string) $style . "\n" . (string) $weight . "\n" . $src . "\n" . (string) $range;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $face = array('family' => $family, 'src' => $src);
                if (null !== $style) $face['fontStyle'] = $style;
                if (null !== $weight) $face['fontWeight'] = $weight;
                if (null !== $range) $face['unicodeRange'] = $range;
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
            if ('' !== $name && '' !== $value && !CssPropertyName::startsLikeNumber($name) && !str_contains(strtolower($value), '!important')) $declarations[$name] = $value;
        }
        return $declarations;
    }

    private function representable(string $target, string $property, string $value): bool
    {
        // Palette expressions must remain live in Global Styles, including
        // the editor canvas. Never resolve a scheme-dependent colour to the
        // reference viewport's literal.
        if (in_array($property, array('color', 'background-color'), true) && preg_match('/^var\(\s*--[A-Za-z0-9_-]+\s*(?:,[^;{}]+)?\)$/', $value)) return true;
        if (str_contains($value, 'var(') || str_contains($value, 'calc(')) return false;
        if (in_array($property, array('color', 'background-color'), true)) {
            // The button element style reaches every button block's link, and
            // WordPress paints those links with white text: a transparent
            // element background (a framework reset such as Tailwind preflight
            // declares `button{background-color:transparent}`) would hide that
            // text instead of the platform default it clears. The reset stays
            // source-owned; the theme states no element button fill.
            if (str_starts_with($target, 'element:') && 'background-color' === $property && self::isTransparentColor($value)) return false;
            return (bool) preg_match('/^(?:#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch)\([^;]+\)|[a-z]+)$/i', $value);
        }
        if (in_array($property, array('font-family', 'font-size', 'line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), true)) return true;
        if ('body' === $target && in_array($property, array('padding', 'margin', 'gap'), true)) return (bool) preg_match('/^[0-9.]+(?:px|rem|em|%|vw|vh)$/i', $value);
        if ('layout' === $target && 'max-width' === $property) return (bool) preg_match('/^[0-9.]+(?:px|rem|em|%|vw|vh)$/i', $value);
        return false;
    }

    /** Whether a colour paints nothing: the `transparent` keyword or a colour with a zero alpha channel. */
    private static function isTransparentColor(string $value): bool
    {
        $value = strtolower(trim($value));
        if ('transparent' === $value) return true;
        if (1 === preg_match('/^#(?:[0-9a-f]{4}|[0-9a-f]{8})$/', $value)) {
            $alpha = 5 === strlen($value) ? $value[4] : substr($value, 7, 2);
            return 1 === preg_match('/^0{1,2}$/', $alpha);
        }
        if (1 === preg_match('/^(?:rgba?|hsla?)\(([^()]*)\)$/', $value, $match)) {
            $body = str_replace(' ', '', $match[1]);
            $alpha = str_contains($body, '/')
                ? substr($body, (int) strrpos($body, '/') + 1)
                : (explode(',', $body)[3] ?? '');
            return 1 === preg_match('/^0(?:%)?(?:\.0+)?$/', trim($alpha));
        }
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

    /**
     * Design-system tokens keyed by their literal value, in the same shape as
     * presets(). Display names ride in `names`.
     *
     * @return array<string,array<string,string>>
     */
    private function systemPresets(DesignSystem $system): array
    {
        $presets = array('color' => array(), 'font-family' => array(), 'font-size' => array(), 'spacing' => array(), 'names' => array());
        foreach ($system->colors as $color) { $presets['color'][$color['color']] = $color['slug']; $presets['names']['color|' . $color['slug']] = $color['name']; }
        foreach ($system->fontFamilies as $family) { $presets['font-family'][$family['fontFamily']] = $family['slug']; $presets['names']['font-family|' . $family['slug']] = $family['name']; }
        foreach ($system->fontSizes as $size) { $presets['font-size'][$size['size']] = $size['slug']; $presets['names']['font-size|' . $size['slug']] = $size['name']; }
        foreach ($system->spacing as $step) { $presets['spacing'][$step['size']] = $step['slug']; $presets['names']['spacing|' . $step['slug']] = $step['name']; }
        return $presets;
    }

    /**
     * Values author CSS declares for element properties, keyed
     * `element:<tag>\n<property>`, across every stylesheet: selectable sets,
     * conditional rules and layers included. Global Styles emits element
     * styles as `:root :where(h1)`, which outranks an author `h1` rule, so a
     * usage-derived element value may only fill a property where every
     * authored value for that element renders the same (see
     * sameElementValue()); otherwise the property stays source-owned.
     *
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,string> $variables Unambiguous source root values.
     * @return array<string,list<string>>
     */
    private function authoredElementProperties(array $assets, array $variables): array
    {
        $authored = array();
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $visitor->visitStyleRules($asset['content'], function (string $prelude, string $body) use (&$authored, $variables): void {
                $targets = $this->targets($prelude);
                if (null === $targets) return;
                foreach ($this->declarations($body) as $name => $value) {
                    if ('font-family' === $name) $value = $this->resolveVariableReferences($value, $variables);
                    foreach ($targets as $target) $authored[$target . "\n" . $name][] = $value;
                }
            });
        }
        return $authored;
    }

    /**
     * Whether an authored element declaration renders the usage-derived
     * value. Undecidable values (shorthands, `var()`, keywords other than the
     * weight names) count as different, so the property stays source-owned.
     */
    private static function sameElementValue(string $property, string $declared, string $value): bool
    {
        $declared = strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', $declared)));
        if (str_contains($declared, 'var(')) return false;
        return match ($property) {
            'color', 'background-color' => null !== CssColor::hex($declared) && CssColor::hex($declared) === CssColor::hex($value),
            'font-family' => '' !== DesignSystem::familyKey($declared) && DesignSystem::familyKey($declared) === DesignSystem::familyKey($value),
            'font-size' => null !== ($px = CssLength::toPx($declared, 16.0)) && abs($px - (CssLength::toPx($value, 16.0) ?? -1.0)) < 0.001,
            'line-height' => $declared === strtolower($value),
            'font-weight' => (array('normal' => '400', 'bold' => '700')[$declared] ?? $declared) === (array('normal' => '400', 'bold' => '700')[strtolower($value)] ?? strtolower($value)),
            default => false,
        };
    }

    /**
     * Adds what the documents render unanimously to the projected styles:
     * the root background, text colour and family, and the element styles
     * of DesignSystem::unanimousElements() (link, button, heading and
     * `h1`–`h6`, with link and button `:hover` colours), as preset
     * references. A value the source
     * stylesheets already project for the same target is kept. A heading
     * colour equal to the root text colour is not emitted: it is the colour
     * headings inherit, and an element style would override that inheritance
     * wherever a section paints its own text (see the root-text guard
     * below). Also adds the
     * content and wide widths when no `main` rule supplied them, and turns
     * off core's default presets so the editor offers the source's tokens.
     *
     * @param array<string,mixed> $theme
     * @param array<string,array<string,string>> $presets
     * @return array<string,mixed>
     */
    private function withSystem(array $theme, DesignSystem $system, array $presets, array $authored = array()): array
    {
        $styles = $theme['styles'];
        $color = fn(string $value): string => null !== ($slug = $system->colorSlug($value)) ? 'var:preset|color|' . $slug : $value;
        $family = fn(string $value): string => null !== ($slug = $system->fontFamilySlug($value)) ? 'var:preset|font-family|' . $slug : $value;
        if (isset($system->root['background'])) $styles['color']['background'] ??= $color($system->root['background']);
        if (isset($system->root['color'])) $styles['color']['text'] ??= $color($system->root['color']);
        if (!empty($system->root['font_family'])) $styles['typography']['fontFamily'] = $family($system->root['font_family']);
        if (isset($system->root['font_size'])) $styles['typography']['fontSize'] = $this->systemValue($system, 'font-size', $system->root['font_size']);
        if (isset($system->root['line_height'])) $styles['typography']['lineHeight'] = $system->root['line_height'];
        // A heading colour equal to the root text colour is the colour
        // headings inherit, not one the source styles them with: as an
        // element style it is a no-op at the root, while WordPress's
        // `h1..h6 { color: … }` output overrides the inherited colour inside
        // every section that paints its own text, leaving headings on a dark
        // band invisible. Such an element colour is not emitted, however it
        // was derived; the headings keep inheriting (see the guard after the
        // element loop).
        $rootText = null;
        $projectedText = $styles['color']['text'] ?? null;
        if (is_string($projectedText)) {
            if (1 === preg_match('/^var:preset\\|color\\|([A-Za-z0-9_-]+)$/', $projectedText, $textMatch)) $projectedText = (string) $system->color($textMatch[1]);
            $rootText = CssColor::hex($projectedText);
        } elseif (is_string($system->root['color'] ?? null)) $rootText = CssColor::hex($system->root['color']);
        // A link or heading colour becomes an element default only when it
        // reads on the background just chosen for the theme root.
        $system = $system->withElementColorsReadableOn(self::rootBackground($styles, $system));
        $size = fn(string $value): string => null !== ($slug = $system->fontSizeSlug($value)) ? 'var:preset|font-size|' . $slug : $value;
        $paths = array(
            'color' => array(array('color', 'text'), $color),
            'background' => array(array('color', 'background'), $color),
            'font_family' => array(array('typography', 'fontFamily'), $family),
            'font_size' => array(array('typography', 'fontSize'), $size),
            'font_weight' => array(array('typography', 'fontWeight'), static fn(string $value): string => $value),
            'line_height' => array(array('typography', 'lineHeight'), static fn(string $value): string => $value),
            'hover_color' => array(array(':hover', 'color', 'text'), $color),
            'hover_background' => array(array(':hover', 'color', 'background'), $color),
        );
        foreach (array('text_transform' => array('typography', 'textTransform'), 'letter_spacing' => array('typography', 'letterSpacing'), 'radius' => array('border', 'radius'), 'padding' => array('spacing', 'padding')) as $field => $path) $paths[$field] = array($path, static fn(string $value): string => $value);
        $properties = array('color' => array('color'), 'background' => array('background', 'background-color'), 'font_family' => array('font-family', 'font'), 'font_size' => array('font-size', 'font'), 'font_weight' => array('font-weight', 'font'), 'line_height' => array('line-height', 'font'));
        $elements = $system->elements;
        if (null !== $system->buttonVariationSlug()) $elements['button'] = $system->buttonVariation;
        foreach ($elements as $element => $values) {
            $tags = 'heading' === $element ? array('h1', 'h2', 'h3', 'h4', 'h5', 'h6') : array($element);
            $element = 'a' === $element ? 'link' : $element;
            foreach ($values as $field => $value) {
                if (!isset($paths[$field])) continue;
                $differs = false;
                foreach ($tags as $tag) foreach ($properties[$field] ?? array() as $property) foreach ($authored['element:' . $tag . "\n" . $property] ?? array() as $declared) $differs = $differs || !(self::sameElementValue($property, $declared, $value) || (1 === preg_match('/^h[1-6]$/', $element) && in_array($field, array('font_size', 'font_weight', 'line_height'), true) && in_array(trim($declared), array('inherit', 'unset'), true)));
                $dominantButton = 'button' === $element && isset($values['padding']);
                if ($differs && !$dominantButton && !('button' === $element && 'color' === $field)) continue;
                [$path, $reference] = $paths[$field];
                if ('button' === $element && null !== ($slug = $system->buttonVariationSlug())) $target = &$styles['blocks']['core/button']['variations'][$slug];
                else $target = &$styles['elements'][$element];
                foreach ($path as $key) $target = &$target[$key];
                // A button colour is what binding relies on when it drops a
                // button's literal (PresetBinding::rendersThroughElement()),
                // and every button block carried it (DesignSystem::withButtonElementsOwnedBy()),
                // so it replaces a colour projected from an authored `button` rule.
                if ($dominantButton || ('button' === $element && 'color' === $path[0]) || ':hover' === $path[0]) $target = $reference($value);
                elseif (1 === preg_match('/^h[1-6]$/', $element) && in_array($field, array('font_size', 'font_weight', 'line_height'), true) && in_array($target, array(null, 'inherit', 'unset'), true)) $target = $reference($value);
                else $target ??= $reference($value);
                unset($target);
            }
        }
        // A heading element colour the root text colour already renders is
        // inherited, never stated: drop it (also where an authored rule
        // projected it first) so `h1..h6` keep following the section they sit
        // in, and prune what the drop leaves empty.
        if (null !== $rootText) foreach (array('heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $level) {
            $written = $styles['elements'][$level]['color']['text'] ?? null;
            if (!is_string($written)) continue;
            if (1 === preg_match('/^var:preset\\|color\\|([A-Za-z0-9_-]+)$/', $written, $writtenMatch)) $written = (string) $system->color($writtenMatch[1]);
            if (null === $written || CssColor::hex($written) !== $rootText) continue;
            unset($styles['elements'][$level]['color']['text']);
            if (array() === ($styles['elements'][$level]['color'] ?? array())) unset($styles['elements'][$level]['color']);
            if (array() === ($styles['elements'][$level] ?? array())) unset($styles['elements'][$level]);
        }
        $settings = $theme['settings'];
        if (!isset($settings['layout']['contentSize']) && isset($system->layout['content'])) {
            $settings['layout']['contentSize'] = $system->layout['content']['value'];
            if (isset($system->layout['wide'])) $settings['layout']['wideSize'] = $system->layout['wide']['value'];
        }
        if (array() !== $presets['color']) $settings['color']['defaultPalette'] = false;
        if (array() !== $presets['font-size']) $settings['typography']['defaultFontSizes'] = false;
        if (array() !== $presets['spacing']) $settings['spacing']['defaultSpacingSizes'] = false;
        $theme['settings'] = $settings;
        $theme['styles'] = $styles;
        return $theme;
    }

    /**
     * The colour the projected `styles.color.background` paints: its preset's
     * value or its literal colour, or the user agent's white canvas when the
     * theme states none (as DesignUsageCollector measures it). Null when the
     * value is not an opaque colour this can resolve.
     *
     * @param array<string,mixed> $styles
     */
    private static function rootBackground(array $styles, DesignSystem $system): ?string
    {
        $value = $styles['color']['background'] ?? null;
        if (null === $value) return '#ffffff';
        if (!is_string($value)) return null;
        if (1 === preg_match('/^var:preset\|color\|([A-Za-z0-9_-]+)$/', $value, $match)) $value = (string) $system->color($match[1]);
        return CssColor::opaque($value) ? CssColor::hex($value) : null;
    }

    /**
     * @param array<int,array<string,mixed>> $selected
     * @param array<string,array<string,string>> $presets
     * @param array<int,array<string,mixed>> $faces
     * @param array<string,string> $viewport
     * @return array<string,mixed>
     */
    private function theme(array $selected, array $presets, array $faces = array(), array $viewport = array(), ?DesignSystem $system = null): array
    {
        $settings = array();
        $names = $presets['names'] ?? array();
        if (array() !== $presets['color']) $settings['color']['palette'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $names['color|' . $slug] ?? $slug, 'color' => $value), array_keys($presets['color']), $presets['color']);
        if (array() !== $presets['font-family']) {
            $families = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $names['font-family|' . $slug] ?? $slug, 'fontFamily' => $value), array_keys($presets['font-family']), $presets['font-family']);
            foreach ($families as $index => $family) {
                $stack = (string) $family['fontFamily'];
                foreach (explode(',', $stack) as $token) {
                    $token = trim($token, " \t\n\r\0\x0B\"'");
                    if (!isset($names['font-family|' . $family['slug']]) && '' !== $token && 1 === preg_match('/^[A-Za-z][A-Za-z0-9 _.\'-]*$/', $token)) { $families[$index]['name'] = $token; break; }
                }
                $familyFaces = array_values(array_filter($faces, static fn(array $face): bool => self::stackReferencesFamily($stack, (string) ($face['family'] ?? ''))));
                if (array() !== $familyFaces) $families[$index]['fontFace'] = $familyFaces;
            }
            $settings['typography']['fontFamilies'] = $families;
        }
        if (array() !== $presets['font-size']) $settings['typography']['fontSizes'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $names['font-size|' . $slug] ?? $slug, 'size' => $value), array_keys($presets['font-size']), $presets['font-size']);
        if (array() !== $presets['spacing']) $settings['spacing']['spacingSizes'] = array_map(static fn(string $value, string $slug): array => array('slug' => $slug, 'name' => $names['spacing|' . $slug] ?? $slug, 'size' => $value), array_keys($presets['spacing']), $presets['spacing']);
        $presetValue = fn(string $type, string $value): string => null === $system ? $this->presetValue($presets[$type], $value, $type) : $this->systemValue($system, $type, $value);
        $styles = array();
        foreach ($selected as $candidate) {
            $target = $candidate['target']; $property = $candidate['property']; $value = $candidate['value'];
            if ('layout' === $target) { $settings['layout']['contentSize'] = $value; continue; }
            $destination = 'body' === $target ? $styles : ($styles['elements'][substr($target, 8)] ?? array());
            if ('color' === $property) $destination['color']['text'] = $presetValue('color', $value);
            elseif ('background-color' === $property) $destination['color']['background'] = $presetValue('color', $value);
            elseif ('font-family' === $property) $destination['typography']['fontFamily'] = $presetValue('font-family', $value);
            elseif ('font-size' === $property) $destination['typography']['fontSize'] = $presetValue('font-size', $value);
            elseif (in_array($property, array('line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), true)) $destination['typography'][str_replace(array('line-height', 'font-weight', 'letter-spacing', 'text-transform', 'font-style'), array('lineHeight', 'fontWeight', 'letterSpacing', 'textTransform', 'fontStyle'), $property)] = $value;
            elseif ('body' === $target) $destination['spacing'][('gap' === $property ? 'blockGap' : $property)] = $presetValue('spacing', $value);
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
        // The source's dominant responsive breakpoints ride alongside the other
        // projected settings. WordPress 7.1 turns them into the `@mobile` and
        // `@tablet` style-state media queries
        // (WP_Theme_JSON::get_viewport_media_queries()), so core layout states
        // and viewport-scoped block styles switch where the source's layouts
        // switch instead of at the stock 480px/782px defaults. Absent keys keep
        // those defaults: sanitize_viewport_settings() falls back per key and
        // drops a tablet that is not larger than mobile.
        if (array() !== $viewport) $settings['viewport'] = $viewport;
        return array('version' => 3, 'settings' => $settings, 'styles' => $styles);
    }

    private function systemValue(DesignSystem $system, string $type, string $value): string
    {
        $slug = match ($type) {
            'color' => $system->colorSlug($value),
            'font-family' => $system->fontFamilySlug($value),
            'font-size' => $system->fontSizeSlug($value),
            default => $system->spacingSlug($value),
        };
        return null === $slug ? $value : 'var:preset|' . $type . '|' . $slug;
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
