<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;

/**
 * A theme design system derived from what the source documents render.
 *
 * Input is the per-document usage the transformer records
 * (`blocks-engine/design-usage/v1`): colours, font sizes, font families,
 * spacing and centred content widths, each weighted by how much of the page
 * it covers. Documents are summed with integer weights, so neither route
 * order nor document order changes the result.
 *
 * Clustering is deterministic. Candidates are visited heaviest first, ties
 * broken by value, and each joins the first cluster within tolerance or
 * starts a new one. The heaviest member names the cluster's value.
 *
 * - Colours: opaque colours within CIE76 ΔE*ab 2.3 (one just-noticeable
 *   difference) merge. Then each low-weight cluster (under 3% of all colour
 *   weight, and not a call-to-action background or button label) merges
 *   into the nearest heavier cluster within ΔE*ab 18, so a one-off shade
 *   (`bg-[#0a1630]` beside a `#050b18` canvas) does not take a palette
 *   slot. 18 keeps neighbouring framework steps apart (Tailwind
 *   blue-500/600 are ΔE 19 apart) while folding shades of the same tint
 *   together. Heavy colours stay distinct however close they are. Only the
 *   JND tolerance binds values to a token: a consolidated shade keeps
 *   rendering as itself. At most 12 colours are kept.
 * - Font sizes: sizes within 0.5px merge. The 8 heaviest are kept and
 *   ordered by size.
 * - Spacing: positive absolute lengths merge only when they are the same
 *   pixel length. The 10 heaviest are kept and ordered by size.
 * - Widths: centred `max-width` containers; the heaviest is the content
 *   width and the heaviest wider one is the wide width.
 * - Font families: grouped by primary family (the first name in the stack,
 *   ignoring case and quotes), keeping the stack most text uses. Only
 *   families some rendered text uses as its primary become presets. A stack
 *   whose primary is a generic family (`ui-monospace, monospace`) renders
 *   the browser's font, not a token, and is dropped.
 *
 * Role names make slugs stable across runs:
 *
 * - `base`: the background that covers the most of the pages. The canvas
 *   counts as covering the whole page.
 * - `contrast`: the colour of most body text (not headings, links or
 *   buttons) on `base`, else the most used text colour.
 * - `primary`, `secondary`: the most used call-to-action backgrounds, else
 *   the most used chromatic colours.
 * - `accent-1` … : every other colour, heaviest first.
 * - Fonts `body` and `heading`: the families of most non-heading and most
 *   heading text. Other families take a slug from their typeface name.
 * - Font sizes: `medium` is the size of most body text; smaller steps are
 *   `small`, `x-small`, …, larger ones `large`, `x-large`, `xx-large`, ….
 * - Spacing: `20`, `30`, … from smallest to largest, matching core's
 *   numeric spacing scale.
 */
final class DesignSystem
{
    public const SCHEMA = 'blocks-engine/design-system/v1';
    public const USAGE_SCHEMA = 'blocks-engine/design-usage/v1';
    public const MAX_COLORS = 12;
    public const MAX_FONT_SIZES = 8;
    public const MAX_SPACING = 10;
    public const COLOR_TOLERANCE = CssColor::JUST_NOTICEABLE_DIFFERENCE;
    public const FONT_SIZE_TOLERANCE_PX = 0.5;
    public const SPACING_TOLERANCE_PX = 0.01;
    /** ΔE*ab within which a low-weight colour cluster merges into a heavier one. */
    public const COLOR_CONSOLIDATION_DISTANCE = 18.0;
    /** Share of all colour weight under which a cluster counts as low-weight. */
    public const LOW_WEIGHT_SHARE = 0.03;
    /** Share of text weight a colour needs to be a `contrast` candidate. */
    public const CONTRAST_CANDIDATE_SHARE = 0.1;
    /** WCAG AA contrast ratio for body text: `contrast` must read against `base`. */
    public const MIN_CONTRAST_RATIO = 4.5;
    /** WCAG AA contrast ratio for large text, below which no colour is `contrast`. */
    public const LOW_CONTRAST_RATIO = 3.0;
    /** Share of the heaviest background's weight that ties it for `base`. */
    public const BASE_TIE_SHARE = 0.9;
    /** Total weight every document contributes; see fromUsage(). */
    public const DOCUMENT_WEIGHT = 1000000;
    private const CSS_WIDE_KEYWORDS = array('inherit', 'initial', 'unset', 'revert', 'revert-layer');
    private const CHROMATIC = 20.0;
    private const GENERIC_FAMILIES = array('serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'math', 'emoji', 'fangsong');

    /**
     * @param list<array{slug:string,name:string,color:string,role:string,weight:int}> $colors
     * @param list<array{slug:string,name:string,size:string,px:float,weight:int}> $fontSizes
     * @param list<array{slug:string,name:string,fontFamily:string,key:string,role:string,weight:int}> $fontFamilies
     * @param list<array{slug:string,name:string,size:string,px:float,weight:int}> $spacing
     * @param array{content?:array{value:string,px:float},wide?:array{value:string,px:float}} $layout
     * @param array<string,mixed> $root Root values every document agrees on.
     * @param array<string,mixed> $elements Link and heading values every document agrees on.
     */
    private function __construct(
        public readonly array $colors,
        public readonly array $fontSizes,
        public readonly array $fontFamilies,
        public readonly array $spacing,
        public readonly array $layout,
        public readonly array $root,
        public readonly array $elements,
        public readonly float $rootPx,
        public readonly int $documentCount,
    ) {
    }

    /**
     * @param array<string,array<string,mixed>> $usageBySource Usage keyed by document source path.
     */
    public static function fromUsage(array $usageBySource): ?self
    {
        $usageBySource = array_filter($usageBySource, static fn(mixed $usage): bool => is_array($usage) && self::USAGE_SCHEMA === ($usage['schema'] ?? null));
        if (array() === $usageBySource) return null;
        ksort($usageBySource, SORT_STRING);

        $textByColor = $backgroundByColor = $ctaByColor = $labelByColor = $sizeWeights = $bodySizeWeights = $spacingRows = $widthRows = array();
        $familyWeights = $familyStacks = $headingFamilyWeights = $bodyFamilyWeights = array();
        $textOnBackground = array();
        $rootPx = array();
        foreach ($usageBySource as $usage) {
            $px = (float) ($usage['root_font_size_px'] ?? 16);
            $rootPx[CssLength::format($px)] = ($rootPx[CssLength::format($px)] ?? 0) + 1;
            $documentMass = self::documentMass($usage);
            foreach ($usage['text'] ?? array() as $row) {
                $weight = self::documentShare((int) ($row['weight'] ?? 0), $documentMass);
                if ($weight <= 0) continue;
                $color = self::opaqueHex($row['color'] ?? null);
                if (null !== $color) {
                    $textByColor[$color] = ($textByColor[$color] ?? 0) + $weight;
                    if ('button' === ($row['role'] ?? null)) $labelByColor[$color] = ($labelByColor[$color] ?? 0) + $weight;
                    $background = self::opaqueHex($row['background'] ?? null);
                    if (null !== $background && 'button' !== ($row['role'] ?? null)) $textOnBackground[$background][$color] = ($textOnBackground[$background][$color] ?? 0) + $weight;
                }
                $size = (float) ($row['font_size_px'] ?? 0);
                if ($size > 0) {
                    $key = CssLength::format($size);
                    $sizeWeights[$key] = ($sizeWeights[$key] ?? 0) + $weight;
                    if ('body' === ($row['role'] ?? null)) $bodySizeWeights[$key] = ($bodySizeWeights[$key] ?? 0) + $weight;
                }
                $stack = is_string($row['font_family'] ?? null) ? trim($row['font_family']) : '';
                $familyKey = self::familyKey($stack);
                if ('' === $familyKey || in_array($familyKey, self::GENERIC_FAMILIES, true) || in_array($familyKey, self::CSS_WIDE_KEYWORDS, true)) continue;
                $familyWeights[$familyKey] = ($familyWeights[$familyKey] ?? 0) + $weight;
                $familyStacks[$familyKey][$stack] = ($familyStacks[$familyKey][$stack] ?? 0) + $weight;
                if ('heading' === ($row['role'] ?? null)) $headingFamilyWeights[$familyKey] = ($headingFamilyWeights[$familyKey] ?? 0) + $weight;
                else $bodyFamilyWeights[$familyKey] = ($bodyFamilyWeights[$familyKey] ?? 0) + $weight;
            }
            foreach ($usage['backgrounds'] ?? array() as $row) {
                $color = self::opaqueHex($row['color'] ?? null);
                $weight = self::documentShare((int) ($row['weight'] ?? 0), $documentMass);
                if (null === $color || $weight <= 0) continue;
                $backgroundByColor[$color] = ($backgroundByColor[$color] ?? 0) + $weight;
                if ('cta' === ($row['role'] ?? null)) $ctaByColor[$color] = ($ctaByColor[$color] ?? 0) + $weight;
            }
            foreach ($usage['spacing'] ?? array() as $row) {
                $px = (float) ($row['px'] ?? 0);
                $value = trim((string) ($row['value'] ?? ''));
                if ($px <= 0 || '' === $value || 1 === preg_match('/(?<!r)em$/i', $value)) continue;
                $spacingRows[] = array('value' => $value, 'px' => $px, 'weight' => (int) ($row['weight'] ?? 0));
            }
            foreach ($usage['widths'] ?? array() as $row) {
                $px = (float) ($row['px'] ?? 0);
                $value = trim((string) ($row['value'] ?? ''));
                if ($px <= 0 || '' === $value) continue;
                $widthRows[] = array('value' => $value, 'px' => $px, 'weight' => (int) ($row['weight'] ?? 0));
            }
        }
        arsort($rootPx);
        $rootFontPx = (float) rtrim((string) array_key_first($rootPx), 'px');

        $root = self::unanimousRoot($usageBySource);
        $colors = self::palette($textByColor, $backgroundByColor, $ctaByColor, $textOnBackground, $labelByColor, $root);
        $fontSizes = self::fontSizes($sizeWeights, $bodySizeWeights, $rootFontPx);
        $fontFamilies = self::fontFamilies($familyWeights, $familyStacks, $headingFamilyWeights, $bodyFamilyWeights, self::familyKey((string) ($root['font_family'] ?? '')), self::unanimousHeadingFamily($usageBySource));
        $spacing = self::spacing($spacingRows);
        $layout = self::layout($widthRows);

        $system = new self($colors, $fontSizes, $fontFamilies, $spacing, $layout, array(), array(), $rootFontPx > 0 ? $rootFontPx : 16.0, count($usageBySource));
        return new self($colors, $fontSizes, $fontFamilies, $spacing, $layout, $root, self::unanimousElements($usageBySource, $system), $system->rootPx, $system->documentCount);
    }

    /** Slug of the palette colour within tolerance of a value, or null. */
    public function colorSlug(string $value): ?string
    {
        $best = null;
        $bestDistance = INF;
        if (!CssColor::opaque($value)) return null;
        foreach ($this->colors as $color) {
            $distance = CssColor::distance($value, $color['color']);
            if (null !== $distance && $distance <= self::COLOR_TOLERANCE && $distance < $bestDistance) {
                $best = $color['slug'];
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /** Slug of the font size within tolerance of an absolute length, or null. */
    public function fontSizeSlug(string $value): ?string
    {
        $px = CssLength::toPx($value, $this->rootPx);
        if (null === $px || $px <= 0) return null;
        foreach ($this->fontSizes as $size) if (abs($size['px'] - $px) <= self::FONT_SIZE_TOLERANCE_PX) return $size['slug'];
        return null;
    }

    /** Slug of the spacing step equal to an absolute length, or null. */
    public function spacingSlug(string $value): ?string
    {
        $px = CssLength::toPx($value, $this->rootPx);
        if (null === $px || $px <= 0) return null;
        foreach ($this->spacing as $step) if (abs($step['px'] - $px) <= self::SPACING_TOLERANCE_PX) return $step['slug'];
        return null;
    }

    /** Slug of the font family preset with the same primary family, or null. */
    public function fontFamilySlug(string $stack): ?string
    {
        $key = self::familyKey($stack);
        foreach ($this->fontFamilies as $family) if ($family['key'] === $key) return $family['slug'];
        return null;
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        return array(
            'schema' => self::SCHEMA,
            'document_count' => $this->documentCount,
            'tolerances' => array('color_delta_e76' => self::COLOR_TOLERANCE, 'color_consolidation_delta_e76' => self::COLOR_CONSOLIDATION_DISTANCE, 'color_low_weight_share' => self::LOW_WEIGHT_SHARE, 'font_size_px' => self::FONT_SIZE_TOLERANCE_PX, 'spacing_px' => self::SPACING_TOLERANCE_PX),
            'palette' => array_map(static fn(array $color): array => array('slug' => $color['slug'], 'color' => $color['color'], 'role' => $color['role'], 'weight' => $color['weight']), $this->colors),
            'font_sizes' => array_map(static fn(array $size): array => array('slug' => $size['slug'], 'size' => $size['size'], 'weight' => $size['weight']), $this->fontSizes),
            'font_families' => array_map(static fn(array $family): array => array('slug' => $family['slug'], 'name' => $family['name'], 'font_family' => $family['fontFamily'], 'weight' => $family['weight']), $this->fontFamilies),
            'spacing' => array_map(static fn(array $step): array => array('slug' => $step['slug'], 'size' => $step['size'], 'weight' => $step['weight']), $this->spacing),
            'layout' => array_map(static fn(array $width): string => $width['value'], $this->layout),
        );
    }

    /**
     * How much of the emitted design the theme's tokens own.
     *
     * - `preset_share`: of the colour, font-size, font-family and spacing
     *   values block attributes carry, the share that are preset references
     *   (`textColor`, `fontSize`, `var:preset|…`) rather than literals. 1.0
     *   when blocks carry no such values. Zero lengths, `auto` and CSS-wide
     *   keywords (`inherit`, …) are not design values and are not counted.
     * - `restyle_coverage`: of the text and background colours rendered on
     *   block-level content, the share that come from a preset reference,
     *   through the block's attributes, its classes' stylesheet rules, an
     *   ancestor, or the root styles; see RestyleCoverage. These follow a
     *   palette edit in Global Styles. A shared template part counts once
     *   per page, as a reader meets it on every route; engine templates count
     *   once. `restyle_text_elements`,
     *   `restyle_text_preset`, `restyle_backgrounds` and
     *   `restyle_backgrounds_preset` are the counts behind it.
     * - `palette_size`: colours in the palette.
     * - `unused_font_families`: primary families (the first name of a
     *   `font-family` stack, unquoted) the source stylesheets declare that no
     *   rendered text uses as its primary, so the theme does not offer them.
     *   Fallbacks later in a stack and `@font-face` descriptors are not
     *   declarations of a family in use.
     * - `generated_css_bytes` / `source_css_bytes` /
     *   `generated_to_source_css_ratio`: CSS the conversion adds (engine
     *   support stylesheets plus inline block `style` attributes) against the
     *   author's stylesheets.
     *
     * @param list<string> $markups Every emitted block document.
     * @param array<int,array<string,mixed>> $assets
     * @param list<string> $routeMarkups For `restyle_coverage`: each
     *   document as often as readers meet it (see above); `$markups` when empty.
     * @return array<string,mixed>
     */
    public function metrics(array $markups, array $assets, array $routeMarkups = array()): array
    {
        $preset = $literal = 0;
        $generated = $source = 0;
        foreach ($markups as $markup) {
            if (preg_match_all('/<!-- wp:[a-z0-9\/-]+ (\{.*?\}) \/?-->/s', $markup, $matches)) {
                foreach ($matches[1] as $json) {
                    $attributes = json_decode($json, true);
                    if (!is_array($attributes)) continue;
                    foreach (array('textColor', 'backgroundColor', 'fontSize', 'fontFamily') as $key) if (is_string($attributes[$key] ?? null)) $preset++;
                    $style = is_array($attributes['style'] ?? null) ? $attributes['style'] : array();
                    $values = array($style['color']['text'] ?? null, $style['color']['background'] ?? null, $style['typography']['fontSize'] ?? null, $style['typography']['fontFamily'] ?? null);
                    foreach (array('padding', 'margin') as $box) foreach ((array) ($style['spacing'][$box] ?? array()) as $value) $values[] = $value;
                    foreach ((array) ($style['spacing']['blockGap'] ?? array()) as $value) $values[] = $value;
                    foreach ($values as $value) {
                        if (!is_string($value) || 1 === preg_match('/^(?:0[a-z%]*|auto|inherit|initial|unset|revert|revert-layer|)$/i', trim($value))) continue;
                        if (str_starts_with($value, 'var:preset|')) $preset++;
                        else $literal++;
                    }
                }
            }
            if (preg_match_all('/\sstyle="([^"]*)"/', $markup, $matches)) foreach ($matches[1] as $style) $generated += strlen($style);
        }
        $declared = array();
        $variables = array();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null) || 'engine-support' === ($asset['source'] ?? null)) continue;
            if (preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $asset['content'], $matches, PREG_SET_ORDER)) foreach ($matches as $match) $variables[$match[1]] ??= trim($match[2]);
        }
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            if ('engine-support' === ($asset['source'] ?? null)) {
                $generated += strlen($asset['content']);
                continue;
            }
            $source += strlen($asset['content']);
            $content = (string) preg_replace('/@font-face\s*\{[^{}]*\}/i', '', $asset['content']);
            if (preg_match_all('/(?<![-\w])font-family\s*:\s*([^;{}!]+)/i', $content, $matches)) {
                foreach ($matches[1] as $stack) {
                    $stack = trim($stack);
                    for ($depth = 0; $depth < 5 && 1 === preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)$/', $stack, $reference); $depth++) $stack = $variables[$reference[1]] ?? trim($reference[2] ?? '');
                    $key = self::familyKey($stack);
                    if ('' === $key || str_contains($key, 'var(') || in_array($key, self::GENERIC_FAMILIES, true) || in_array($key, self::CSS_WIDE_KEYWORDS, true)) continue;
                    $declared[$key] ??= self::primaryFamily($stack);
                }
            }
        }
        $rendered = array_fill_keys(array_column($this->fontFamilies, 'key'), true);
        $unused = array_values(array_unique(array_diff_key($declared, $rendered)));
        sort($unused, SORT_STRING);
        $total = $preset + $literal;
        $restyle = RestyleCoverage::measure(array() === $routeMarkups ? $markups : $routeMarkups, $assets, isset($this->root['color']) && null !== $this->colorSlug((string) $this->root['color']));
        return array(
            'preset_share' => 0 === $total ? 1.0 : round($preset / $total, 4),
            'restyle_coverage' => $restyle['share'],
            'restyle_text_elements' => $restyle['text_elements'],
            'restyle_text_preset' => $restyle['text_preset'],
            'restyle_backgrounds' => $restyle['backgrounds'],
            'restyle_backgrounds_preset' => $restyle['backgrounds_preset'],
            'preset_value_count' => $preset,
            'literal_value_count' => $literal,
            'palette_size' => count($this->colors),
            'font_size_count' => count($this->fontSizes),
            'spacing_size_count' => count($this->spacing),
            'font_family_count' => count($this->fontFamilies),
            'unused_font_families' => $unused,
            'generated_css_bytes' => $generated,
            'source_css_bytes' => $source,
            'generated_to_source_css_ratio' => 0 === $source ? null : round($generated / $source, 4),
        );
    }

    /**
     * @param array<string,int> $text
     * @param array<string,int> $backgrounds
     * @param array<string,int> $cta
     * @param array<string,array<string,int>> $textOnBackground
     * @param array<string,int> $labels Button label colours.
     * @param array<string,string> $root Root values every document agrees on.
     * @return list<array{slug:string,name:string,color:string,role:string,weight:int}>
     */
    private static function palette(array $text, array $backgrounds, array $cta, array $textOnBackground, array $labels = array(), array $root = array()): array
    {
        $total = array();
        foreach (array($text, $backgrounds) as $weights) foreach ($weights as $color => $weight) $total[$color] = ($total[$color] ?? 0) + $weight;
        if (array() === $total) return array();
        $clusters = self::consolidateColors(self::clusterColors($total), $cta + $labels);
        $memberOf = array();
        foreach ($clusters as $index => $cluster) foreach ($cluster['members'] as $member) $memberOf[$member] = $index;
        $sum = static function (array $weights) use ($memberOf): array {
            $byCluster = array();
            foreach ($weights as $color => $weight) $byCluster[$memberOf[$color]] = ($byCluster[$memberOf[$color]] ?? 0) + $weight;
            return $byCluster;
        };
        $rank = static function (array $byCluster) use ($clusters): array {
            $indexes = array_keys($byCluster);
            usort($indexes, static fn(int $a, int $b): int => ($byCluster[$b] <=> $byCluster[$a]) ?: strcmp($clusters[$a]['color'], $clusters[$b]['color']));
            return $indexes;
        };

        $roles = array();
        // `base` is the root background every document renders, else the
        // heaviest background; among backgrounds about as heavy as that one
        // (BASE_TIE_SHARE), the first that some text reads on wins, so
        // `contrast` exists whenever the site has a readable pairing.
        $rootBackground = isset($root['background']) ? ($memberOf[$root['background']] ?? null) : null;
        $backgroundWeights = $sum($backgrounds);
        $baseCandidates = null !== $rootBackground ? array($rootBackground) : array_values(array_filter($rank($backgroundWeights), static fn(int $index): bool => $backgroundWeights[$index] >= self::BASE_TIE_SHARE * max($backgroundWeights ?: array(0))));
        $rootText = isset($root['color']) ? ($memberOf[$root['color']] ?? null) : null;
        $base = $baseCandidates[0] ?? null;
        $contrast = null;
        foreach ($baseCandidates as $candidate) {
            $onBase = array();
            foreach ($textOnBackground as $background => $colors) {
                if (($memberOf[$background] ?? null) !== $candidate) continue;
                foreach ($colors as $color => $weight) if (isset($memberOf[$color])) $onBase[$color] = ($onBase[$color] ?? 0) + $weight;
            }
            $contrast = self::contrastCluster($clusters, $candidate, $sum($onBase), $sum($text), $rootText);
            if (null !== $contrast) { $base = $candidate; break; }
        }
        if (null !== $base) $roles[$base] = 'base';
        if (null === $base) $contrast = $rank($sum($text))[0] ?? null;
        if (null !== $contrast && !isset($roles[$contrast])) $roles[$contrast] = 'contrast';
        $accents = array();
        foreach ($rank($sum($cta)) as $index) if (!isset($roles[$index])) $accents[] = $index;
        $chromatic = array_values(array_filter($rank(array_map(static fn(array $cluster): int => $cluster['weight'], $clusters)), static fn(int $index): bool => (CssColor::chroma($clusters[$index]['color']) ?? 0.0) >= self::CHROMATIC));
        foreach ($chromatic as $index) if (!isset($roles[$index]) && !in_array($index, $accents, true)) $accents[] = $index;
        foreach (array('primary', 'secondary') as $offset => $role) if (isset($accents[$offset])) $roles[$accents[$offset]] = $role;

        $ordered = array();
        foreach (array('base', 'contrast', 'primary', 'secondary') as $role) {
            $index = array_search($role, $roles, true);
            if (false !== $index) $ordered[] = $index;
        }
        $accent = 0;
        foreach ($rank(array_map(static fn(array $cluster): int => $cluster['weight'], $clusters)) as $index) {
            if (count($ordered) >= self::MAX_COLORS) break;
            if (isset($roles[$index])) continue;
            $roles[$index] = 'accent-' . (++$accent);
            $ordered[] = $index;
        }
        return array_map(static fn(int $index): array => array(
            'slug' => $roles[$index],
            'name' => ucwords(str_replace('-', ' ', $roles[$index])),
            'color' => $clusters[$index]['color'],
            'role' => preg_replace('/-\d+$/', '', $roles[$index]) ?? $roles[$index],
            'weight' => $clusters[$index]['weight'],
        ), $ordered);
    }

    /**
     * The cluster that names `contrast`: the main text colour on `base`.
     *
     * Candidates are the high-weight text colours (each at least
     * CONTRAST_CANDIDATE_SHARE of the text) drawn on `base`, else of all
     * text. The root text colour every document renders wins when it is one
     * of them and reads against `base` (WCAG ratio of at least
     * MIN_CONTRAST_RATIO), so root `styles` and the role agree. Otherwise the
     * candidate with the highest contrast ratio against `base` wins. A colour
     * below MIN_CONTRAST_RATIO is only taken when no text colour reaches it,
     * and never below LOW_CONTRAST_RATIO.
     *
     * @param list<array{color:string,weight:int,members:list<string>}> $clusters
     * @param array<int,int> $onBase Text weight per cluster drawn on `base`.
     * @param array<int,int> $text Text weight per cluster.
     */
    private static function contrastCluster(array $clusters, int $base, array $onBase, array $text, ?int $root): ?int
    {
        unset($onBase[$base], $text[$base]);
        $ratio = static fn(int $index): float => CssColor::contrastRatio($clusters[$index]['color'], $clusters[$base]['color']) ?? 1.0;
        $highWeight = static function (array $weights): array {
            $total = array_sum($weights);
            return array_keys(array_filter($weights, static fn(int $weight): bool => $weight >= self::CONTRAST_CANDIDATE_SHARE * $total));
        };
        $best = static function (array $indexes, float $minimum) use ($ratio, $clusters): ?int {
            usort($indexes, static fn(int $a, int $b): int => ($ratio($b) <=> $ratio($a)) ?: strcmp($clusters[$a]['color'], $clusters[$b]['color']));
            return null !== ($indexes[0] ?? null) && $ratio($indexes[0]) >= $minimum ? $indexes[0] : null;
        };
        foreach (array($onBase, $text) as $pool) {
            $candidates = $highWeight($pool);
            if (null !== $root && in_array($root, $candidates, true) && $ratio($root) >= self::MIN_CONTRAST_RATIO) return $root;
            $choice = $best($candidates, self::MIN_CONTRAST_RATIO);
            if (null !== $choice) return $choice;
        }
        return $best(array_keys($text), self::LOW_CONTRAST_RATIO);
    }

    /**
     * @param array<string,int> $weights
     * @return list<array{color:string,weight:int,members:list<string>}>
     */
    private static function clusterColors(array $weights): array
    {
        $colors = array_keys($weights);
        usort($colors, static fn(string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: strcmp($a, $b));
        $clusters = array();
        foreach ($colors as $color) {
            foreach ($clusters as &$cluster) {
                if ((CssColor::distance($color, $cluster['color']) ?? INF) <= self::COLOR_TOLERANCE) {
                    $cluster['weight'] += $weights[$color];
                    $cluster['members'][] = $color;
                    continue 2;
                }
            }
            unset($cluster);
            $clusters[] = array('color' => $color, 'weight' => $weights[$color], 'members' => array($color));
        }
        return $clusters;
    }

    /**
     * Merge each low-weight cluster into the nearest heavier cluster within
     * COLOR_CONSOLIDATION_DISTANCE. Clusters are visited heaviest first, so a
     * low-weight cluster joins a cluster that already holds whatever merged
     * into it; the heavier cluster keeps its colour. A cluster with a
     * call-to-action background or button label colour is a brand colour
     * however little area it covers, and is not absorbed.
     *
     * @param list<array{color:string,weight:int,members:list<string>}> $clusters Heaviest first.
     * @param array<string,int> $cta Colours that are never absorbed.
     * @return list<array{color:string,weight:int,members:list<string>}>
     */
    public static function consolidateColors(array $clusters, array $cta = array()): array
    {
        $total = array_sum(array_column($clusters, 'weight'));
        $kept = array();
        foreach ($clusters as $cluster) {
            $lowWeight = $cluster['weight'] < self::LOW_WEIGHT_SHARE * $total && array() === array_intersect_key($cta, array_flip($cluster['members']));
            $nearest = null;
            $nearestDistance = INF;
            if ($lowWeight) {
                foreach ($kept as $index => $candidate) {
                    if ($candidate['weight'] <= $cluster['weight']) continue;
                    $distance = CssColor::distance($cluster['color'], $candidate['color']) ?? INF;
                    if ($distance <= self::COLOR_CONSOLIDATION_DISTANCE && $distance < $nearestDistance) {
                        $nearest = $index;
                        $nearestDistance = $distance;
                    }
                }
            }
            if (null === $nearest) {
                $kept[] = $cluster;
                continue;
            }
            $kept[$nearest]['weight'] += $cluster['weight'];
            array_push($kept[$nearest]['members'], ...$cluster['members']);
        }
        return $kept;
    }

    /**
     * @param array<string,int> $weights Pixel size => text mass.
     * @param array<string,int> $bodyWeights
     * @return list<array{slug:string,name:string,size:string,px:float,weight:int}>
     */
    private static function fontSizes(array $weights, array $bodyWeights, float $rootPx): array
    {
        $clusters = self::clusterLengths($weights, self::FONT_SIZE_TOLERANCE_PX);
        if (array() === $clusters) return array();
        $clusters = array_slice($clusters, 0, self::MAX_FONT_SIZES);
        usort($clusters, static fn(array $a, array $b): int => $a['px'] <=> $b['px']);
        $medium = 0;
        $bodyBest = -1;
        foreach ($clusters as $index => $cluster) {
            $bodyWeight = 0;
            foreach ($cluster['members'] as $member) $bodyWeight += $bodyWeights[CssLength::format($member)] ?? 0;
            if ($bodyWeight > $bodyBest) { $bodyBest = $bodyWeight; $medium = $index; }
        }
        if ($bodyBest <= 0) {
            $heaviest = -1;
            foreach ($clusters as $index => $cluster) if ($cluster['weight'] > $heaviest) { $heaviest = $cluster['weight']; $medium = $index; }
        }
        $sizes = array();
        foreach ($clusters as $index => $cluster) {
            $step = $index - $medium;
            $slug = 0 === $step ? 'medium' : ((abs($step) > 1 ? str_repeat('x', abs($step) - 1) . '-' : '') . ($step < 0 ? 'small' : 'large'));
            $sizes[] = array('slug' => $slug, 'name' => ucwords(str_replace('-', ' ', (string) preg_replace_callback('/^x+(?=-)/', static fn(array $m): string => strtoupper($m[0]), $slug))), 'size' => self::lengthText($cluster['px'], $rootPx), 'px' => $cluster['px'], 'weight' => $cluster['weight']);
        }
        return $sizes;
    }

    /**
     * @param list<array{value:string,px:float,weight:int}> $rows
     * @return list<array{slug:string,name:string,size:string,px:float,weight:int}>
     */
    private static function spacing(array $rows): array
    {
        $weights = array();
        $spellings = array();
        foreach ($rows as $row) {
            $key = CssLength::format($row['px']);
            $weights[$key] = ($weights[$key] ?? 0) + $row['weight'];
            $spellings[$key][$row['value']] = ($spellings[$key][$row['value']] ?? 0) + $row['weight'];
        }
        $clusters = array_slice(self::clusterLengths($weights, self::SPACING_TOLERANCE_PX), 0, self::MAX_SPACING);
        usort($clusters, static fn(array $a, array $b): int => $a['px'] <=> $b['px']);
        $steps = array();
        foreach ($clusters as $index => $cluster) {
            $choices = $spellings[CssLength::format($cluster['px'])] ?? array();
            uksort($choices, static fn(string $a, string $b): int => ($choices[$b] <=> $choices[$a]) ?: strcmp($a, $b));
            $value = (string) (array_key_first($choices) ?? CssLength::format($cluster['px']));
            $slug = (string) (($index + 2) * 10);
            $steps[] = array('slug' => $slug, 'name' => $value, 'size' => $value, 'px' => $cluster['px'], 'weight' => $cluster['weight']);
        }
        return $steps;
    }

    /**
     * @param list<array{value:string,px:float,weight:int}> $rows
     * @return array{content?:array{value:string,px:float},wide?:array{value:string,px:float}}
     */
    private static function layout(array $rows): array
    {
        $weights = array();
        $values = array();
        foreach ($rows as $row) {
            $key = CssLength::format($row['px']);
            $weights[$key] = ($weights[$key] ?? 0) + $row['weight'];
            $values[$key] ??= $row['value'];
            if (strcmp($row['value'], $values[$key]) < 0) $values[$key] = $row['value'];
        }
        if (array() === $weights) return array();
        uksort($weights, static fn(string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: ((float) $a <=> (float) $b));
        $content = (string) array_key_first($weights);
        $layout = array('content' => array('value' => $values[$content], 'px' => (float) $content));
        foreach ($weights as $key => $_) {
            if ((float) $key > (float) $content) { $layout['wide'] = array('value' => $values[$key], 'px' => (float) $key); break; }
        }
        return $layout;
    }

    /**
     * @param array<string,int> $familyWeights
     * @param array<string,array<string,int>> $stacks
     * @param array<string,int> $headingWeights
     * @param array<string,int> $bodyWeights
     * @param string $rootKey Primary family every document's root renders, or ''.
     * @param string $headingKey Primary family every heading renders, or ''.
     * @return list<array{slug:string,name:string,fontFamily:string,key:string,role:string,weight:int}>
     */
    private static function fontFamilies(array $familyWeights, array $stacks, array $headingWeights, array $bodyWeights, string $rootKey = '', string $headingKey = ''): array
    {
        if (array() === $familyWeights) return array();
        $heaviest = static function (array $weights): ?string {
            if (array() === $weights) return null;
            uksort($weights, static fn(string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: strcmp($a, $b));
            return (string) array_key_first($weights);
        };
        // Root `styles.typography` and `styles.elements.heading` reference
        // these families, so they name the roles whenever they are rendered.
        $body = isset($familyWeights[$rootKey]) ? $rootKey : $heaviest($bodyWeights);
        $heading = isset($familyWeights[$headingKey]) ? $headingKey : $heaviest($headingWeights);
        if (null === $body) $body = $heading;
        $families = array();
        $roles = array();
        if (null !== $body) $roles[$body] = 'body';
        if (null !== $heading && $heading !== $body) $roles[$heading] = 'heading';
        $order = array_keys($familyWeights);
        usort($order, static fn(string $a, string $b): int => ($familyWeights[$b] <=> $familyWeights[$a]) ?: strcmp($a, $b));
        $keys = array_values(array_filter(array($heading !== $body ? $heading : null, $body)));
        foreach ($order as $key) if (!in_array($key, $keys, true)) $keys[] = $key;
        $used = array('body' => true, 'heading' => true);
        foreach ($keys as $key) {
            $choices = $stacks[$key];
            uksort($choices, static fn(string $a, string $b): int => ($choices[$b] <=> $choices[$a]) ?: strcmp($a, $b));
            $stack = (string) array_key_first($choices);
            $name = self::familyName($stack);
            $slug = $roles[$key] ?? null;
            if (null === $slug) {
                $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
                $slug = '' === $base ? 'family' : $base;
                for ($suffix = 2; isset($used[$slug]); $suffix++) $slug = $base . '-' . $suffix;
            }
            $used[$slug] = true;
            $families[] = array('slug' => $slug, 'name' => $name, 'fontFamily' => $stack, 'key' => $key, 'role' => $roles[$key] ?? 'family', 'weight' => $familyWeights[$key]);
        }
        return $families;
    }

    /**
     * @param array<string,int> $weights Pixel text => weight.
     * @return list<array{px:float,weight:int,members:list<float>}>
     */
    private static function clusterLengths(array $weights, float $tolerance): array
    {
        $lengths = array_keys($weights);
        usort($lengths, static fn(string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: ((float) $a <=> (float) $b));
        $clusters = array();
        foreach ($lengths as $length) {
            $px = (float) $length;
            foreach ($clusters as &$cluster) {
                if (abs($cluster['px'] - $px) <= $tolerance) {
                    $cluster['weight'] += $weights[$length];
                    $cluster['members'][] = $px;
                    continue 2;
                }
            }
            unset($cluster);
            $clusters[] = array('px' => $px, 'weight' => $weights[$length], 'members' => array($px));
        }
        return $clusters;
    }

    /**
     * Root values (canvas background, body text colour and family) that every
     * document renders identically and no document varies conditionally.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,string>
     */
    private static function unanimousRoot(array $usageBySource): array
    {
        $values = array('background' => array(), 'color' => array(), 'font_family' => array());
        $authoredBackground = true;
        $conditional = array();
        foreach ($usageBySource as $usage) {
            $root = is_array($usage['root'] ?? null) ? $usage['root'] : array();
            foreach ($values as $key => $_) $values[$key][(string) ($root[$key] ?? '')] = true;
            $authoredBackground = $authoredBackground && true === ($root['background_authored'] ?? false);
            foreach ($root['conditional'] ?? array() as $family) $conditional[(string) $family] = true;
        }
        $root = array();
        if ($authoredBackground && 1 === count($values['background']) && !isset($conditional['background'])) $root['background'] = (string) array_key_first($values['background']);
        $color = (string) array_key_first($values['color']);
        if (1 === count($values['color']) && '' !== $color && '#000000' !== $color && !isset($conditional['color'])) $root['color'] = $color;
        $family = (string) array_key_first($values['font_family']);
        if (1 === count($values['font_family']) && '' !== $family && !isset($conditional['font-family'])) $root['font_family'] = $family;
        return $root;
    }

    /**
     * Link colour and heading family/colour that every link or heading on every
     * document renders. Anything less than unanimous would restyle text that
     * currently inherits a different value.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,array<string,string>>
     */
    private static function unanimousElements(array $usageBySource, self $system): array
    {
        $link = array();
        $heading = array('color' => array(), 'font_family' => array());
        $conditional = array();
        foreach ($usageBySource as $usage) foreach ($usage['root']['conditional'] ?? array() as $family) $conditional[(string) $family] = true;
        foreach ($usageBySource as $usage) foreach ($usage['text'] ?? array() as $row) {
            if ('link' === ($row['role'] ?? null)) $link[(string) ($row['color'] ?? '')] = true;
            if ('heading' === ($row['role'] ?? null)) {
                $heading['color'][(string) ($row['color'] ?? '')] = true;
                $heading['font_family'][self::familyKey((string) ($row['font_family'] ?? ''))] = (string) ($row['font_family'] ?? '');
            }
        }
        // Text that inherits a root value the source varies by media query
        // would stop following it under an element style.
        if (isset($conditional['color'])) $link = $heading['color'] = array();
        if (isset($conditional['font-family'])) $heading['font_family'] = array();
        $elements = array();
        if (1 === count($link) && null !== $system->colorSlug((string) array_key_first($link))) $elements['link']['color'] = (string) array_key_first($link);
        if (1 === count($heading['color']) && null !== $system->colorSlug((string) array_key_first($heading['color']))) $elements['heading']['color'] = (string) array_key_first($heading['color']);
        $family = (string) reset($heading['font_family']);
        if (1 === count($heading['font_family']) && '' !== $family && null !== $system->fontFamilySlug($family)) $elements['heading']['font_family'] = $family;
        return $elements;
    }

    /**
     * Primary family key every heading on every document renders, or ''.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     */
    private static function unanimousHeadingFamily(array $usageBySource): string
    {
        $keys = array();
        foreach ($usageBySource as $usage) foreach ($usage['text'] ?? array() as $row) {
            if ('heading' === ($row['role'] ?? null)) $keys[self::familyKey((string) ($row['font_family'] ?? ''))] = true;
        }
        return 1 === count($keys) ? (string) array_key_first($keys) : '';
    }

    /**
     * A document's rendered text mass, the denominator of its shares.
     *
     * @param array<string,mixed> $usage
     */
    private static function documentMass(array $usage): int
    {
        $mass = (int) ($usage['total_mass'] ?? 0);
        if ($mass <= 0) foreach ($usage['text'] ?? array() as $row) $mass += max(0, (int) ($row['weight'] ?? 0));
        return max(1, $mass);
    }

    /**
     * A usage weight as its share of the document, in DOCUMENT_WEIGHT units.
     * Every document then weighs the same in total, so a route's say does
     * not grow with how much text it has.
     */
    private static function documentShare(int $weight, int $documentMass): int
    {
        if ($weight <= 0) return 0;
        return max(1, intdiv($weight * self::DOCUMENT_WEIGHT + intdiv($documentMass, 2), $documentMass));
    }

    private static function opaqueHex(mixed $value): ?string
    {
        if (!is_string($value) || !CssColor::opaque($value)) return null;
        return CssColor::hex($value);
    }

    /**
     * Case-, quote- and whitespace-insensitive identity of a family stack:
     * its primary family. Stacks that only differ in their fallbacks render
     * the same typeface wherever it is available, so they are one family.
     */
    public static function familyKey(string $stack): string
    {
        return strtolower(self::primaryFamily($stack));
    }

    /** The first family name of a stack, unquoted. */
    private static function primaryFamily(string $stack): string
    {
        foreach (explode(',', $stack) as $part) {
            $part = trim($part, " \t\n\r\0\x0B\"'");
            if ('' !== $part) return (string) preg_replace('/\s+/', ' ', $part);
        }
        return '';
    }

    private static function familyName(string $stack): string
    {
        foreach (explode(',', $stack) as $token) {
            $token = trim($token, " \t\n\r\0\x0B\"'");
            if ('' !== $token && !in_array(strtolower($token), self::GENERIC_FAMILIES, true) && 1 === preg_match('/^[A-Za-z][A-Za-z0-9 _.\'-]*$/', $token)) return $token;
        }
        return trim(explode(',', $stack)[0], " \t\n\r\0\x0B\"'");
    }

    /** Pixel size as rem when the document root is not the default 16px. */
    private static function lengthText(float $px, float $rootPx): string
    {
        if (16.0 === $rootPx || $rootPx <= 0) return CssLength::format($px);
        return rtrim(rtrim(number_format($px / $rootPx, 4, '.', ''), '0'), '.') . 'rem';
    }
}
