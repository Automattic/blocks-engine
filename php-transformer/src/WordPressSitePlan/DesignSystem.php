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
 * - Colours: a preset binding never changes a painted colour. A block
 *   attribute, a stylesheet declaration or an element style binds a colour
 *   only to a token whose value is that exact 8-bit colour (colorSlug()),
 *   so an import paints 1:1 until a site owner edits the palette.
 *
 *   Grouping only names roles and breaks ties: opaque colours within
 *   CIE76 ΔE*ab 2.3 (one just-noticeable difference) form a family, and
 *   each family within ΔE*ab 5 of a heavier one joins the nearest, however
 *   much it is used, so a near-duplicate neutral (`#1f2937` beside
 *   `#1e293b`) does not compete with a distinct colour for a role. Roles
 *   take each family's heaviest colour; every other painted colour then
 *   takes a token of its own exact value (`accent-N`) by its own painted
 *   weight, heaviest first, whatever family it ranks in (on a tie a
 *   family's own colour first, then the heavier family). So a near shade
 *   painted on many large surfaces keeps a token ahead of a distinct colour
 *   painted on one tiny element. At most 12 colours are kept: the lightest
 *   painted colours past the bound keep their literal everywhere and are
 *   reported under `literal_colors`, never bound to a near token.
 *
 *   Clustering, slot ranking and `accent-N` order use each colour's
 *   prominence: the mean of its share of the document's area (text mass
 *   and painted backgrounds) and its share of the document's coloured
 *   runs (text runs and painted elements). Area alone would rank an
 *   accent that marks many small elements (icons, eyebrows, bullets, a
 *   brand-coloured word) below a neutral used once on a large block; how
 *   often a reader meets a colour matters as much as how much ink it
 *   covers. A colour used through an authored custom property (`var()`)
 *   counts like a literal, since usage records the value each element
 *   renders. `base` and `contrast` are still chosen from area alone.
 * - Font sizes: sizes within 0.5px merge. The 8 heaviest keep their
 *   original names and representatives; missing heading sizes are additive.
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
 * - `accent-1` … : every other painted colour by its own weight, heaviest
 *   first.
 * - Font `body`: the root family every document renders, else the family
 *   of most non-heading text.
 * - Font `heading`: the family heading elements (`core/heading` blocks)
 *   render. Each document's headings share one document weight, so a
 *   route with many headings does not outvote the others. Within a
 *   document a heading weighs by its rank and rendered size, not its
 *   length: HEADING_LEVEL_FACTOR (3 for `h1`, 2 for `h2`, 1 below) times
 *   its font size over the root size, per heading. A page title outweighs
 *   a list of small entry titles, which only name the role on routes that
 *   have nothing larger. Headings that render the body family or a system UI stack
 *   (`-apple-system`, `BlinkMacSystemFont`, `system-ui`, `Segoe UI`) are
 *   not a heading typeface: the heaviest other family wins when it renders
 *   at least HEADING_FAMILY_SHARE of the heading weight. Otherwise the
 *   heaviest heading family names the role, as long as it is not the body
 *   family.
 * - Other families take a slug from their typeface name.
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
    /** Float slack for lengths that are equal: binding never changes a rendered length. */
    private const EXACT_PX = 0.001;
    /**
     * ΔE*ab within which a colour family joins a heavier one for slot
     * ranking (see the class comment). It never decides what binds.
     */
    public const COLOR_CONSOLIDATION_DISTANCE = 5.0;
    /** Weight of one heading by level, before its size: a page title above section and entry titles. */
    public const HEADING_LEVEL_FACTOR = array(1 => 3, 2 => 2);
    /** Share of heading text a family other than the body's needs to be `heading`. */
    public const HEADING_FAMILY_SHARE = 0.25;
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
    /** Primary families of the platform UI font stacks. */
    private const SYSTEM_UI_FAMILIES = array('-apple-system', 'blinkmacsystemfont', 'system-ui', 'segoe ui', 'ui-sans-serif');
    private const GENERIC_FAMILIES = array('serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'math', 'emoji', 'fangsong');

    /**
     * @param list<array{slug:string,name:string,color:string,role:string,weight:int}> $colors
     * @param list<array{slug:string,name:string,size:string,px:float,weight:int}> $fontSizes
     * @param list<array{slug:string,name:string,fontFamily:string,key:string,role:string,weight:int}> $fontFamilies
     * @param list<array{slug:string,name:string,size:string,px:float,weight:int}> $spacing
     * @param array{content?:array{value:string,px:float},wide?:array{value:string,px:float}} $layout
     * @param array<string,mixed> $root Root values every document agrees on.
     * @param array<string,mixed> $elements Shared element values and proven no-op per-level heading typography.
     * @param list<string> $literalColors Painted opaque colours the palette bound left without a token, heaviest first.
     * @param array<string,string> $buttonVariation Dominant look scoped to matching button blocks when an element would reach unowned values.
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
        public readonly array $literalColors = array(),
        public readonly array $buttonVariation = array(),
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

        $textByColor = $backgroundByColor = $ctaByColor = $prominence = $sizeWeights = $bodySizeWeights = $spacingRows = $widthRows = array();
        $familyWeights = $familyStacks = $headingFamilyWeights = $headingFamilyProminence = $bodyFamilyWeights = array();
        $textOnBackground = $headingSizes = array();
        $rootPx = array();
        foreach ($usageBySource as $usage) {
            $px = (float) ($usage['root_font_size_px'] ?? 16);
            $rootPx[CssLength::format($px)] = ($rootPx[CssLength::format($px)] ?? 0) + 1;
            $documentMass = self::documentMass($usage);
            $documentRuns = self::documentRuns($usage);
            $documentHeadings = array();
            foreach ($usage['text'] ?? array() as $row) {
                $weight = self::documentShare((int) ($row['weight'] ?? 0), $documentMass);
                if ($weight <= 0) continue;
                $color = self::opaqueHex($row['color'] ?? null);
                if (null !== $color) {
                    $prominence[$color] = ($prominence[$color] ?? 0) + self::prominence($weight, (int) ($row['count'] ?? 1), $documentRuns);
                    $textByColor[$color] = ($textByColor[$color] ?? 0) + $weight;
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
                // Generic families are rendered stacks too, even without a font face.
                if ('' === $familyKey || in_array($familyKey, self::CSS_WIDE_KEYWORDS, true)) continue;
                $familyWeights[$familyKey] = ($familyWeights[$familyKey] ?? 0) + $weight;
                $familyStacks[$familyKey][$stack] = ($familyStacks[$familyKey][$stack] ?? 0) + $weight;
                if ('heading' === ($row['role'] ?? null)) $documentHeadings[$familyKey] = ($documentHeadings[$familyKey] ?? 0) + self::headingProminence($row, $px);
                else $bodyFamilyWeights[$familyKey] = ($bodyFamilyWeights[$familyKey] ?? 0) + $weight;
            }
            $headingMass = array_sum($documentHeadings);
            foreach ($documentHeadings as $familyKey => $headingWeight) {
                $headingFamilyWeights[$familyKey] = ($headingFamilyWeights[$familyKey] ?? 0) + (int) round(self::DOCUMENT_WEIGHT * $headingWeight / $headingMass);
                $headingFamilyProminence[$familyKey] = ($headingFamilyProminence[$familyKey] ?? 0) + (int) round(1000 * $headingWeight);
            }
            foreach ($usage['backgrounds'] ?? array() as $row) {
                $color = self::opaqueHex($row['color'] ?? null);
                $weight = self::documentShare((int) ($row['weight'] ?? 0), $documentMass);
                if (null === $color || $weight <= 0) continue;
                $prominence[$color] = ($prominence[$color] ?? 0) + self::prominence($weight, (int) ($row['count'] ?? 1), $documentRuns);
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
        // Choose a rendered body run, not the html rem base. Keep the
        // typography tuple together so size, family and leading agree.
        $bodyTypography = array();
        foreach ($usageBySource as $usage) foreach ($usage['text'] ?? array() as $row) {
            if ('body' !== ($row['role'] ?? null)) continue;
            $tuple = array('font_size' => CssLength::format((float) $row['font_size_px']), 'font_family' => $row['font_family'] ?? '', 'line_height' => $row['line_height'] ?? 'normal');
            $key = json_encode($tuple, JSON_THROW_ON_ERROR);
            $bodyTypography[$key] = ($bodyTypography[$key] ?? 0) + self::documentShare((int) $row['weight'], self::documentMass($usage));
        }
        if (array() !== $bodyTypography) {
            uksort($bodyTypography, static fn(string $a, string $b): int => ($bodyTypography[$b] <=> $bodyTypography[$a]) ?: strcmp($a, $b));
            $dominant = json_decode((string) array_key_first($bodyTypography), true, 512, JSON_THROW_ON_ERROR);
            // Route-dependent families remain source-owned. Where a shared
            // family exists, name the body role from readable text instead.
            if (!isset($root['font_family']) || '' === $dominant['font_family']) unset($dominant['font_family']);
            $root = array_replace($root, $dominant);
        }
        // An explicit pixel size replaces the UA medium keyword, which also
        // changes bare monospace sizing. Require root author evidence on every
        // document before applying the rendered size site-wide.
        foreach ($usageBySource as $usage) {
            if (true !== ($usage['root']['font_size_authored'] ?? false)) {
                unset($root['font_size']);
                break;
            }
        }
        $colors = self::palette($prominence, $textByColor, $backgroundByColor, $ctaByColor, $textOnBackground, $root);
        $literalColors = array_keys(array_diff_key($prominence, array_flip(array_column($colors, 'color'))));
        usort($literalColors, static fn(string $a, string $b): int => ($prominence[$b] <=> $prominence[$a]) ?: strcmp($a, $b));
        foreach ($usageBySource as $usage) foreach ($usage['heading_typography'] ?? array() as $heading) {
            if (($heading['size_px'] ?? 0) > 0) $headingSizes[CssLength::format((float) $heading['size_px'])] = 1;
        }
        $buttonLook = self::dominantButtonLook($usageBySource);
        if (isset($buttonLook['font_size'])) $headingSizes[$buttonLook['font_size']] = 1;
        $fontSizes = self::fontSizes($sizeWeights, $bodySizeWeights, $rootFontPx, $headingSizes);
        $fontFamilies = self::fontFamilies($familyWeights, $familyStacks, $headingFamilyWeights, $bodyFamilyWeights, self::familyKey((string) ($root['font_family'] ?? '')), self::unanimousHeadingFamily($usageBySource), $headingFamilyProminence);
        $spacing = self::spacing($spacingRows);
        $layout = self::layout($widthRows);

        $system = new self($colors, $fontSizes, $fontFamilies, $spacing, $layout, array(), array(), $rootFontPx > 0 ? $rootFontPx : 16.0, count($usageBySource), $literalColors);
        if (array() !== $buttonLook && (null === $system->colorSlug($buttonLook['background']) || null === $system->colorSlug($buttonLook['color']) || null === $system->fontFamilySlug($buttonLook['font_family']) || null === $system->fontSizeSlug($buttonLook['font_size']))) $buttonLook = array();
        return new self($colors, $fontSizes, $fontFamilies, $spacing, $layout, $root, array_replace(self::unanimousElements($usageBySource, $system), array() === $buttonLook ? array() : array('button' => $buttonLook)), $system->rootPx, $system->documentCount, $literalColors);
    }

    /**
     * Slug of the token whose value is exactly this colour, or null. The
     * one colour binding rule: block attributes, stylesheet bindings and
     * element styles all bind through this method, and only to a token
     * that paints the same 8-bit colour, so binding never changes what
     * renders. Translucent colours never bind here; see translucentColor().
     */
    public function colorSlug(string $value): ?string
    {
        if (!CssColor::opaque($value)) return null;
        $hex = CssColor::hex($value);
        if (null === $hex) return null;
        foreach ($this->colors as $color) if ($hex === CssColor::hex($color['color'])) return $color['slug'];
        return null;
    }

    /**
     * A translucent colour whose opaque colour is a token, as the token's
     * slug and the alpha as a whole percentage (`rgb(245 241 232 / .7)` is
     * `contrast` at 70). Null for opaque, fully transparent or unmatched
     * values, and when the alpha is not within 0.2 points of a whole
     * percentage: an 8-bit alpha (`#f5f1e8b3`, 70.2%) is, below one alpha
     * step; `.333` is not, so `color-mix()` would not restate it.
     *
     * @return array{slug:string,percent:int}|null
     */
    public function translucentColor(string $value): ?array
    {
        $color = CssColor::parse($value);
        if (null === $color || $color[3] >= 1.0 || $color[3] <= 0.0) return null;
        $percent = (int) round($color[3] * 100);
        if (abs($color[3] * 100 - $percent) > 0.2) return null;
        $slug = $this->colorSlug(sprintf('#%02x%02x%02x', (int) round($color[0]), (int) round($color[1]), (int) round($color[2])));
        return null === $slug ? null : array('slug' => $slug, 'percent' => $percent);
    }

    /**
     * The system without the button element styles some `core/button` in
     * the markup would take from them. An element style reaches every
     * button that does not carry its own value; the usage it comes from
     * only covers buttons that render a fill, so an outlined button that
     * converts to `core/button` would otherwise be painted. A button value
     * stays only when every button block carries that property itself. A
     * clustered look additionally requires every block to own its typography,
     * shape and colours (core's outline style owns its transparent fill).
     *
     * @param list<string> $markups Every page's block markup.
     */
    public function withButtonElementsOwnedBy(array $markups): self
    {
        if (!isset($this->elements['button'])) return $this;
        $dominant = isset($this->elements['button']['padding']);
        $owned = array('color' => true, 'background' => true, 'font_family' => true, 'font_size' => true, 'font_weight' => true);
        $unstyled = false;
        foreach ($markups as $markup) {
            preg_match_all('/<!-- wp:button(?: (\{.*?\}))? \/?-->/s', $markup, $buttons);
            foreach ($buttons[1] as $json) {
                $attributes = json_decode($json, true);
                if (empty($attributes['style'])) $unstyled = true;
            }
        }
        foreach ($markups as $markup) {
            if (!preg_match_all('/<!-- wp:button(?: (\{.*?\}))? \/?-->/s', $markup, $matches)) continue;
            foreach ($matches[1] as $json) {
                $attributes = '' === $json ? array() : json_decode($json, true);
                if (!is_array($attributes)) $attributes = array();
                $style = is_array($attributes['style'] ?? null) ? $attributes['style'] : array();
                if ($dominant) {
                    // A dominant look may replace only source-owned values.
                    // An unstyled sibling would otherwise acquire this look.
                    $outline = str_contains((string) ($attributes['className'] ?? ''), 'is-style-outline');
                    if (!isset($style['typography']['fontFamily'], $style['typography']['fontSize'], $style['typography']['fontWeight'], $style['border']['radius'], $style['spacing']['padding'])
                        || (isset($this->elements['button']['text_transform']) && !isset($style['typography']['textTransform']))
                        || (isset($this->elements['button']['letter_spacing']) && !isset($style['typography']['letterSpacing']))
                        || (!isset($style['color']['text']) && !isset($attributes['textColor']))
                        || (!$outline && !isset($style['color']['background']) && !isset($attributes['backgroundColor']))) {
                        $elements = $this->elements;
                        unset($elements['button']);
                        // A plain unstyled button does not establish a button system.
                        $variation = $unstyled ? array() : $this->elements['button'];
                        return new self($this->colors, $this->fontSizes, $this->fontFamilies, $this->spacing, $this->layout, $this->root, $elements, $this->rootPx, $this->documentCount, $this->literalColors, $variation);
                    }
                }
                $carried = array(
                    'color' => isset($attributes['textColor']) || isset($style['color']['text']),
                    'background' => isset($attributes['backgroundColor']) || isset($attributes['gradient']) || isset($style['color']['background']) || isset($style['color']['gradient']),
                    'font_family' => isset($attributes['fontFamily']) || isset($style['typography']['fontFamily']),
                    'font_size' => isset($attributes['fontSize']) || isset($style['typography']['fontSize']),
                    'font_weight' => isset($style['typography']['fontWeight']),
                );
                foreach ($carried as $field => $present) if (!$present) $owned[$field] = false;
            }
        }
        if ($dominant) return $this;
        $button = $this->elements['button'];
        foreach ($owned as $field => $isOwned) if (!$isOwned) unset($button[$field]);
        if (!$owned['color']) unset($button['hover_color']);
        if (!$owned['background']) unset($button['hover_background']);
        $elements = $this->elements;
        if (array() === $button) unset($elements['button']); else $elements['button'] = $button;
        return new self($this->colors, $this->fontSizes, $this->fontFamilies, $this->spacing, $this->layout, $this->root, $elements, $this->rootPx, $this->documentCount, $this->literalColors, $this->buttonVariation);
    }

    /**
     * The system without the link and heading text colours (`color`,
     * `hover_color` of `link`, `heading`, `h1`–`h6`) that do not read on
     * the theme's root background: below the large-text WCAG ratio
     * (LOW_CONTRAST_RATIO) against it. unanimousElements() finds a colour
     * every element of a kind renders, which can hold only because every
     * such element sits on a coloured band (each H1 in a dark hero that
     * sets `color:#fff`). An element style reaches every element of its
     * kind, including new content on the root background, so that colour
     * stays where the source paints it: on the band, which the element
     * inherits from, or on the element itself. Painted output is unchanged:
     * binding never drops a link or heading literal for an element style
     * (PresetBinding::rendersThroughElement() is buttons only). Buttons paint
     * their own background and keep their colours.
     *
     * @param string|null $background The colour `styles.color.background`
     *   resolves to (ThemeJsonProjection::rootBackground()); null when it
     *   cannot be resolved, which keeps every colour.
     */
    public function withElementColorsReadableOn(?string $background): self
    {
        if (null === $background || null === CssColor::hex($background)) return $this;
        $elements = $this->elements;
        foreach ($elements as $element => $values) {
            if ('link' !== $element && 'heading' !== $element && 1 !== preg_match('/^h[1-6]$/', $element)) continue;
            foreach (array('color', 'hover_color') as $field) {
                if (!isset($values[$field])) continue;
                $ratio = CssColor::contrastRatio($values[$field], $background);
                if (null !== $ratio && $ratio >= self::LOW_CONTRAST_RATIO) continue;
                unset($values[$field]);
            }
            if (array() === $values) unset($elements[$element]); else $elements[$element] = $values;
        }
        if ($elements === $this->elements) return $this;
        return new self($this->colors, $this->fontSizes, $this->fontFamilies, $this->spacing, $this->layout, $this->root, $elements, $this->rootPx, $this->documentCount, $this->literalColors, $this->buttonVariation);
    }

    public function buttonVariationSlug(): ?string
    {
        return array() === $this->buttonVariation ? null : ($this->colorSlug($this->buttonVariation['background']) ?? 'primary');
    }

    /** A palette colour's value by slug, or null. */
    public function color(string $slug): ?string
    {
        foreach ($this->colors as $color) if ($slug === $color['slug']) return $color['color'];
        return null;
    }

    /** Slug of the font size that renders an absolute length exactly, or null. */
    public function fontSizeSlug(string $value): ?string
    {
        $px = CssLength::toPx($value, $this->rootPx);
        if (null === $px || $px <= 0) return null;
        foreach ($this->fontSizes as $size) if (abs($size['px'] - $px) <= self::EXACT_PX) return $size['slug'];
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
            'tolerances' => array('color_delta_e76' => self::COLOR_TOLERANCE, 'color_consolidation_delta_e76' => self::COLOR_CONSOLIDATION_DISTANCE, 'font_size_px' => self::FONT_SIZE_TOLERANCE_PX, 'spacing_px' => self::SPACING_TOLERANCE_PX),
            'palette' => array_map(static fn(array $color): array => array('slug' => $color['slug'], 'color' => $color['color'], 'role' => $color['role'], 'weight' => $color['weight']), $this->colors),
            'literal_colors' => $this->literalColors,
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
     * - `refusal_reasons`: text and background colour values binding left
     *   as literals, by reason (see PresetBinding::REFUSAL_REASONS), and
     *   `refused_value_count` their total. Content that should not follow
     *   the palette (a "before" mock-up in its own colours) shows up here
     *   rather than being forced onto a token. A class-driven value counted
     *   here can still follow the palette through a bound stylesheet rule,
     *   which `restyle_coverage` measures.
     * - `palette_size`: colours in the palette.
     * - `literal_color_count`: painted opaque colours past the palette
     *   bound, which keep their literal (report `literal_colors`).
     * - `unused_font_families`: primary families (the first name of a
     *   `font-family` stack, unquoted) the source stylesheets declare that no
     *   rendered text uses as its primary, so the theme does not offer them.
     *   Fallbacks later in a stack and `@font-face` descriptors are not
     *   declarations of a family in use.
     * - `theme_css_bytes`: CSS the theme ships, every stylesheet asset
     *   (author projections, per-page copies, shared chrome, engine support)
     *   plus inline block `style` attributes.
     * - `unique_source_css_bytes`: the author's stylesheets as captured,
     *   each counted once however often the theme copies it (see
     *   WordPressSitePlan::uniqueSourceCssBytes()). Without it, the bytes of
     *   the shipped author stylesheets.
     * - `engine_css_bytes`: CSS the conversion adds rather than projects:
     *   engine support stylesheets plus inline block `style` attributes.
     * - `generated_to_source_css_ratio`: `theme_css_bytes` over
     *   `unique_source_css_bytes`, so three per-page copies of one
     *   stylesheet read as about 3.
     *
     * @param list<string> $markups Every emitted block document.
     * @param array<int,array<string,mixed>> $assets
     * @param list<string> $routeMarkups For `restyle_coverage`: each
     *   document as often as readers meet it (see above); `$markups` when empty.
     * @param array<string,int> $refusals From PresetBinding::refusals().
     * @param int|null $uniqueSourceBytes Captured author CSS, each stylesheet once.
     * @return array<string,mixed>
     */
    public function metrics(array $markups, array $assets, array $routeMarkups = array(), array $refusals = array(), ?int $uniqueSourceBytes = null): array
    {
        $preset = $literal = 0;
        $inline = $engine = $shipped = 0;
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
            if (preg_match_all('/\sstyle="([^"]*)"/', $markup, $matches)) foreach ($matches[1] as $style) $inline += strlen($style);
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
                $engine += strlen($asset['content']);
                continue;
            }
            $shipped += strlen($asset['content']);
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
            'refused_value_count' => array_sum($refusals),
            'refusal_reasons' => $refusals,
            'palette_size' => count($this->colors),
            'literal_color_count' => count($this->literalColors),
            'font_size_count' => count($this->fontSizes),
            'spacing_size_count' => count($this->spacing),
            'font_family_count' => count($this->fontFamilies),
            'unused_font_families' => $unused,
            'theme_css_bytes' => $theme = $shipped + $engine + $inline,
            'unique_source_css_bytes' => $source = $uniqueSourceBytes ?? $shipped,
            'engine_css_bytes' => $engine + $inline,
            'generated_to_source_css_ratio' => 0 === $source ? null : round($theme / $source, 4),
        );
    }

    /**
     * @param array<string,int> $prominence Colour => prominence (see the class comment), which ranks clusters.
     * @param array<string,int> $text Colour => text area, which with $backgrounds chooses `base` and `contrast`.
     * @param array<string,int> $backgrounds
     * @param array<string,int> $cta
     * @param array<string,array<string,int>> $textOnBackground
     * @param array<string,string> $root Root values every document agrees on.
     * @return list<array{slug:string,name:string,color:string,role:string,weight:int}>
     */
    private static function palette(array $prominence, array $text, array $backgrounds, array $cta, array $textOnBackground, array $root = array()): array
    {
        if (array() === $prominence) return array();
        $clusters = self::consolidateColors(self::clusterColors($prominence));
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
        $token = static fn(string $slug, string $color, int $weight): array => array(
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'color' => $color,
            'role' => preg_replace('/-\d+$/', '', $slug) ?? $slug,
            'weight' => $weight,
        );
        $palette = array_map(static fn(int $index): array => $token($roles[$index], $clusters[$index]['color'], $clusters[$index]['weight']), $ordered);
        // Every other painted colour competes for the remaining slots on its
        // own painted weight, heaviest first, whatever family it ranks in:
        // a heavy near shade outranks a barely used distinct colour, and the
        // lightest painted colours are the ones left literal. Grouping only
        // breaks ties: a family's own colour before a near shade of another,
        // then the heavier family, then the value.
        $taken = array_flip(array_column($palette, 'color'));
        $rest = array_keys(array_diff_key($prominence, $taken));
        $isLead = static fn(string $color): int => $clusters[$memberOf[$color]]['color'] === $color ? 1 : 0;
        usort($rest, static fn(string $a, string $b): int => ($prominence[$b] <=> $prominence[$a]) ?: ($isLead($b) <=> $isLead($a)) ?: ($clusters[$memberOf[$b]]['weight'] <=> $clusters[$memberOf[$a]]['weight']) ?: strcmp($a, $b));
        $accent = 0;
        foreach ($rest as $color) {
            if (count($palette) >= self::MAX_COLORS) break;
            $palette[] = $token('accent-' . (++$accent), $color, $prominence[$color]);
        }
        return $palette;
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
     * Merge each cluster into the nearest heavier kept cluster within
     * COLOR_CONSOLIDATION_DISTANCE, however much it is used, so a shade a
     * reader cannot tell from a heavier colour does not outrank a distinct
     * colour for a role. Merging only groups for roles and ties: a painted
     * member still competes for a token of its own exact value on its own
     * weight (see palette()). Clusters
     * are visited heaviest first, so a cluster joins one that already holds
     * whatever merged into it; the heavier cluster keeps its colour.
     *
     * @param list<array{color:string,weight:int,members:list<string>}> $clusters Heaviest first.
     * @return list<array{color:string,weight:int,members:list<string>}>
     */
    public static function consolidateColors(array $clusters): array
    {
        $kept = array();
        foreach ($clusters as $cluster) {
            $nearest = null;
            $nearestDistance = INF;
            foreach ($kept as $index => $candidate) {
                if ($candidate['weight'] < $cluster['weight']) continue;
                $distance = CssColor::distance($cluster['color'], $candidate['color']) ?? INF;
                if ($distance <= self::COLOR_CONSOLIDATION_DISTANCE && $distance < $nearestDistance) {
                    $nearest = $index;
                    $nearestDistance = $distance;
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
     * @param array<string,int> $headingSizes Rendered heading sizes appended after the baseline scale.
     * @return list<array{slug:string,name:string,size:string,px:float,weight:int}>
     */
    private static function fontSizes(array $weights, array $bodyWeights, float $rootPx, array $headingSizes = array()): array
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
        // Do not re-cluster or rename the established scale: existing source
        // bindings must keep exactly the same value when headings are added.
        uksort($headingSizes, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
        foreach ($headingSizes as $px => $weight) {
            foreach ($sizes as $size) if (abs($size['px'] - (float) $px) <= self::EXACT_PX) continue 2;
            // The slug must stay unchanged by the kebab-case that derives the
            // editor's preset class, and that kebab-case splits letters from
            // digits: "heading-28px" would save as has-heading-28px-font-size
            // and re-render as has-heading-28-px-font-size, so the editor
            // rejects the block. Keep the bare number, decimals dashed.
            $slug = 'heading-' . str_replace('.', '-', rtrim((string) $px, 'px'));
            $sizes[] = array('slug' => $slug, 'name' => 'Heading ' . $px, 'size' => self::lengthText((float) $px, $rootPx), 'px' => (float) $px, 'weight' => $weight);
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
    private static function fontFamilies(array $familyWeights, array $stacks, array $headingWeights, array $bodyWeights, string $rootKey = '', string $headingKey = '', array $headingProminence = array()): array
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
        $heading = isset($familyWeights[$headingKey]) ? $headingKey : self::headingFamily($headingWeights, $body, $headingProminence);
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
     * Family key that names the `heading` role (see the class comment): the
     * heaviest heading family that is neither the body family nor a system
     * UI stack and renders at least HEADING_FAMILY_SHARE of the heading weight,
     * else the heaviest heading family. Routes that tie (each opens with
     * one `h1` in its own family) are separated by the headings' level and
     * size before the family name.
     *
     * @param array<string,int> $headingWeights
     * @param array<string,int> $prominence Summed per-heading weight (see headingProminence()), the tie-break.
     */
    private static function headingFamily(array $headingWeights, ?string $body, array $prominence = array()): ?string
    {
        if (array() === $headingWeights) return null;
        uksort($headingWeights, static fn(string $a, string $b): int => ($headingWeights[$b] <=> $headingWeights[$a]) ?: (($prominence[$b] ?? 0) <=> ($prominence[$a] ?? 0)) ?: strcmp($a, $b));
        $total = array_sum($headingWeights);
        foreach ($headingWeights as $key => $weight) {
            if ($key === $body || self::isSystemUiFamily($key)) continue;
            if ($weight >= self::HEADING_FAMILY_SHARE * $total) return $key;
            break;
        }
        return (string) array_key_first($headingWeights);
    }

    /** Whether a family key names a platform UI stack rather than a typeface. */
    public static function isSystemUiFamily(string $key): bool
    {
        return in_array($key, self::SYSTEM_UI_FAMILIES, true);
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
     * Element styles (`styles.elements` in theme.json) from what every
     * element of a kind renders on every document: link colour and `:hover`
     * colour; button text and background colour, family, size and weight
     * and their `:hover` colours; heading colour, family and weight; and,
     * per heading level, colour, family, size and weight where that level
     * differs from the heading values. Element styles reach every element of
     * the kind, so only a unanimous value cannot restyle text that renders
     * something else today. Colours, families and sizes must be tokens (a
     * colour within the JND of the token's own value). A `:hover` colour
     * counts only when every element of the kind has one hover rule giving
     * the same token. A property a media-conditional rule can change for any
     * element of the kind (a breakpoint size, a dark-mode colour) is left to
     * the source: an element style would override the variant. A link or
     * heading text colour must also read on the theme's root background,
     * which only the theme.json projection knows; it applies that check
     * through withElementColorsReadableOn().
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,array<string,string>>
     */
    private static function unanimousElements(array $usageBySource, self $system): array
    {
        $values = $headingTypography = array();
        $conditional = array();
        foreach ($usageBySource as $usage) foreach ($usage['root']['conditional'] ?? array() as $family) $conditional[(string) $family] = true;
        // A media-conditional variant of the property makes the element render
        // something else under that condition, which an element style would
        // override: such a field has no single value.
        $families = array('color' => 'color', 'hover_color' => 'color', 'font_family' => 'font-family', 'font_size' => 'font-size', 'font_weight' => 'font-weight', 'background' => 'background', 'hover_background' => 'background');
        $observe = static function (string $element, string $field, mixed $value, array $row) use (&$values, $families): void {
            $varies = in_array($families[$field], (array) ($row['conditional'] ?? array()), true);
            $values[$element][$field][$varies || null === $value ? '' : (string) $value] = true;
        };
        foreach ($usageBySource as $usage) {
            foreach ($usage['text'] ?? array() as $row) {
                $role = (string) ($row['role'] ?? '');
                $elements = match ($role) {
                    'link' => array('link'),
                    'button' => array('button'),
                    'heading' => array('heading', 'h' . (int) ($row['heading_level'] ?? 0)),
                    default => array(),
                };
                foreach ($elements as $element) {
                    $observe($element, 'color', true === ($row['color_authored'] ?? false) ? ($row['color'] ?? null) : null, $row);
                    if ('link' === $element || 'button' === $element) $observe($element, 'hover_color', $row['hover_color'] ?? null, $row);
                    if ('link' === $element) continue;
                    $observe($element, 'font_family', self::familyKey((string) ($row['font_family'] ?? '')) . "\n" . (string) ($row['font_family'] ?? ''), $row);
                    $observe($element, 'font_weight', $row['font_weight'] ?? null, $row);
                    if ('heading' !== $element) $observe($element, 'font_size', $row['font_size_px'] ?? null, $row);
                }
            }
            foreach ($usage['backgrounds'] ?? array() as $row) {
                if ('cta' !== ($row['role'] ?? null)) continue;
                $observe('button', 'background', $row['color'] ?? null, $row);
                $observe('button', 'hover_background', $row['hover_color'] ?? null, $row);
            }
        }
        // Text that inherits a root value the source varies by media query
        // would stop following it under an element style.
        $varied = array('color' => isset($conditional['color']), 'hover_color' => isset($conditional['color']), 'font_family' => isset($conditional['font-family']), 'font_size' => isset($conditional['font-size']));
        $token = static function (string $field, string $value) use ($system): ?string {
            if ('' === $value) return null;
            return match ($field) {
                'color', 'hover_color', 'background', 'hover_background' => null !== $system->colorSlug($value) ? $value : null,
                'font_family' => null !== $system->fontFamilySlug(explode("\n", $value, 2)[1] ?? '') && '' !== explode("\n", $value, 2)[0] ? explode("\n", $value, 2)[1] : null,
                'font_size' => null !== $system->fontSizeSlug($value . 'px') ? $value . 'px' : null,
                'font_weight' => 1 === preg_match('/^\d+$/', $value) ? $value : null,
                default => null,
            };
        };
        $elements = array();
        foreach ($values as $element => $fields) {
            foreach ($fields as $field => $observed) {
                if (1 !== count($observed) || !empty($varied[$field])) continue;
                $value = $token($field, (string) array_key_first($observed));
                if (null === $value) continue;
                // A heading level states only what differs from `heading`.
                if (1 === preg_match('/^h[1-6]$/', $element) && ($elements['heading'][$field] ?? null) === $value) continue;
                $elements[$element][$field] = $value;
            }
        }
        // Text runs cannot prove ownership: a heading may contain styled
        // descendants or inherit its typography. Require every heading's own
        // matched declarations to agree before adding an element default.
        foreach ($usageBySource as $usage) foreach ($usage['heading_typography'] ?? array() as $row) {
            foreach (array('font_size', 'font_weight', 'line_height') as $field) $headingTypography['h' . $row['level']][$field][(string) ($row[$field] ?? '')] = true;
        }
        foreach ($elements as $element => &$fields) {
            if ('heading' === $element || preg_match('/^h[1-6]$/', $element)) unset($fields['font_size'], $fields['font_weight'], $fields['line_height']);
        }
        unset($fields);
        foreach ($headingTypography as $level => $fields) foreach ($fields as $field => $observed) {
            if (1 !== count($observed)) continue;
            $value = (string) array_key_first($observed);
            $value = 'line_height' === $field ? $value : $token($field, $value);
            if (null !== $value && '' !== $value) $elements[$level][$field] = $value;
        }
        // Black text on a document is the user agent's default, not a design
        // choice the theme should state for buttons.
        if ('#000000' === ($elements['button']['color'] ?? null)) unset($elements['button']['color']);
        ksort($elements, SORT_STRING);
        return $elements;
    }

    /**
     * Cluster complete authored looks, not individual properties: the element
     * default must be a look that actually renders. Each route has equal say.
     * Ties and isolated controls do not establish a design system.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,string>
     */
    private static function dominantButtonLook(array $usageBySource): array
    {
        $weights = $looks = array();
        $count = 0;
        foreach ($usageBySource as $usage) {
            $rows = $usage['button_looks'] ?? array();
            foreach ($rows as $look) {
                ++$count;
                // A partially observed look (state, media or inherited value,
                // or an outline control with no authored fill) cannot become
                // the element look. It does not vote; binding still requires
                // every block to own the values it yields to the element.
                if (!isset($look['font_family'], $look['font_size'], $look['font_weight'], $look['radius'], $look['padding'], $look['color'], $look['background'])) continue;
                ksort($look);
                $key = json_encode($look, JSON_THROW_ON_ERROR);
                $looks[$key] = $look;
                $weights[$key] = ($weights[$key] ?? 0) + self::documentShare(1, count($rows));
            }
        }
        if ($count < 3 || array() === $weights) return array();
        uksort($weights, static fn(string $a, string $b): int => ($weights[$b] <=> $weights[$a]) ?: strcmp($a, $b));
        $key = array_key_first($weights);
        $ranked = array_values($weights);
        if ($weights[$key] * 2 < array_sum($weights) || ($ranked[1] ?? 0) === $weights[$key]) return array();
        return $looks[$key];
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
     * One heading row's weight in its document (see the class comment):
     * HEADING_LEVEL_FACTOR for its level times its rendered size over the
     * root size, per heading run.
     *
     * @param array<string,mixed> $row
     */
    private static function headingProminence(array $row, float $rootPx): float
    {
        $level = self::HEADING_LEVEL_FACTOR[(int) ($row['heading_level'] ?? 0)] ?? 1;
        $size = (float) ($row['font_size_px'] ?? 0) > 0 ? (float) $row['font_size_px'] / max(1.0, $rootPx) : 1.0;
        return $level * $size * max(1, (int) ($row['count'] ?? 1));
    }

    /**
     * A document's coloured runs: its text runs and painted elements, the
     * denominator of occurrence shares.
     *
     * @param array<string,mixed> $usage
     */
    private static function documentRuns(array $usage): int
    {
        $runs = 0;
        foreach (array('text', 'backgrounds') as $kind) foreach ($usage[$kind] ?? array() as $row) $runs += max(0, (int) ($row['count'] ?? 1));
        return max(1, $runs);
    }

    /**
     * A colour row's prominence (see the class comment): the mean of its
     * area share and its share of the document's coloured runs, in
     * DOCUMENT_WEIGHT units.
     */
    private static function prominence(int $areaShare, int $runs, int $documentRuns): int
    {
        return intdiv($areaShare + self::documentShare(max(1, $runs), $documentRuns), 2);
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
