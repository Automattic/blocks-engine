<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFontFamily;
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
 *   A near shade the palette omits (nearShades) follows its family's
 *   representative instead through a stylesheet derivation
 *   (nearShadeColor()): `rgb(from var(--wp--preset--color--base) calc(r - 8)
 *   calc(g - 8) calc(b - 11))` paints the exact source shade at the default
 *   palette and moves with the representative when the palette is edited
 *   (#3043, #6754). Block attributes never carry a derivation: WordPress's
 *   safecss strips relative colours and color-mix() from inline styles, so
 *   a block-level near shade keeps its exact literal.
 *
 *   Grouping selects distinct palette entries: opaque colours within
 *   CIE76 ΔE*ab 2.3 (one just-noticeable difference) form a family, and
 *   each family within ΔE*ab 5 of a heavier one joins the nearest, however
 *   much it is used, so a near-duplicate neutral (`#1f2937` beside
 *   `#1e293b`) does not compete with a distinct colour for a role. Roles
 *   take each family's heaviest colour. Other distinct families receive
 *   exact tokens by painted weight. Near shades are not palette entries:
 *   stylesheets derive them from their family's representative. Unpainted
 *   stylesheet colours do not create palette entries.
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
 * Painted root custom properties named `--background`, `--foreground`,
 * `--primary` and `--secondary` take precedence over usage-ranked roles,
 * using their exact colour, not a cluster representative. Conflicting root
 * declarations vote by document count, then colour value; duplicate colours
 * take the first role in that order. Without them, ranking is unchanged.
 *
 * Role names make slugs stable across runs:
 *
 * - `base`: the background that covers the most of the pages. The canvas
 *   counts as covering the whole page.
 * - `contrast`: the colour of most body text (not headings, links or
 *   buttons) on `base`, else the most used text colour.
 * - `primary`, `secondary`: the most used call-to-action backgrounds, else
 *   the most used chromatic colours.
 * - `accent-1` … : other distinct colour families by painted weight,
 *   heaviest first. Labels prefer source tokens, then hue and lightness;
 *   every label is distinct within its category (distinctColorNames()).
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
    // Additional exact colours are editable block values, not new evidence
    // for site-wide defaults or a reason to rewrite every utility rule.
    public const PRIMARY_COLORS = 12;
    /** Role of the exact tokens withBlockColors() adds for block-only colours. */
    public const BLOCK_COLOR_ROLE = 'block';
    public const MAX_FONT_SIZES = 8;
    public const MAX_SPACING = 10;
    public const COLOR_TOLERANCE = CssColor::JUST_NOTICEABLE_DIFFERENCE;
    public const FONT_SIZE_TOLERANCE_PX = 0.5;
    public const SPACING_TOLERANCE_PX = 0.01;
    /** Float slack for lengths that are equal: binding never changes a rendered length. */
    private const EXACT_PX = 0.001;
    /**
     * ΔE*ab within which a colour family joins a heavier one, which then
     * represents it in the palette; stylesheets derive the omitted shade
     * from it (nearShadeColor()). It never decides what binds exactly.
     */
    public const COLOR_CONSOLIDATION_DISTANCE = 5.0;

    /** Bound on memoized colorSlug() answers per instance. */
    private const COLOR_SLUG_MEMO_LIMIT = 8192;

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
    /** Lightness words for renaming colliding colour names, each with its exclusive HSL lightness upper bound. */
    private const LIGHTNESS_WORDS = array('Deep' => 0.2, 'Dark' => 0.32, 'Dusky' => 0.44, 'Mid' => 0.56, 'Soft' => 0.68, 'Light' => 0.8, 'Pale' => 1.01);
    private const GENERIC_FAMILIES = array('serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'math', 'emoji', 'fangsong');

    /**
     * @param list<array{slug:string,name:string,color:string,role:string,weight:int}> $colors
     * @param list<array{slug:string,name:string,size:string,px:float,weight:int}> $fontSizes
     * @param list<array{slug:string,name:string,fontFamily:string,key:string,role:string,weight:int}> $fontFamilies
     * @param list<array{slug:string,name:string,size:string,px:float,weight:int}> $spacing
     * @param array{content?:array{value:string,px:float},wide?:array{value:string,px:float}} $layout
     * @param array<string,mixed> $root Root values every document agrees on.
     * @param array<string,mixed> $elements Shared element values and proven no-op per-level heading typography.
     * @param list<string> $literalColors Painted opaque colours without a token (retained for report compatibility).
     * @param array<string,string> $buttonVariation Dominant look scoped to matching button blocks when an element would reach unowned values.
     * @param array<string,string> $nearShades Opaque hex of each near shade the palette omits => hex of its family's representative token (nearShadeColor()).
     */
    /** @var list<array{slug:string,name:string,color:string,role:string,weight:int}> */
    public readonly array $colors;
    /** @var list<array{slug:string,name:string,size:string,px:float,weight:int}> */
    public readonly array $fontSizes;
    /** @var list<array{slug:string,name:string,size:string,px:float,weight:int}> */
    public readonly array $spacing;

    private function __construct(
        array $colors,
        array $fontSizes,
        public readonly array $fontFamilies,
        array $spacing,
        public readonly array $layout,
        public readonly array $root,
        public readonly array $elements,
        public readonly float $rootPx,
        public readonly int $documentCount,
        public readonly array $literalColors = array(),
        public readonly array $buttonVariation = array(),
        public readonly array $nearShades = array(),
    ) {
        // Every preset in a category gets a display name a person can tell
        // apart from its siblings; slugs never change here.
        $this->colors = self::distinctColorNames($colors);
        $this->fontSizes = self::distinctLengthNames($fontSizes);
        $this->spacing = self::distinctLengthNames($spacing);
    }

    /**
     * Palette lookups built once per instance: the first token per painted
     * hex, the first index per slug, and memoized colorSlug() answers. Every
     * binding asks colorSlug() for each painted value, so a scan of the whole
     * palette per question grows with palette size × values (both grow with
     * the site); the index answers each in constant time with the same
     * first-match result.
     *
     * @var array{hex:array<string,string>,slug:array<string,int>}|null
     */
    private ?array $colorIndex = null;

    /** @var array<string,?string> */
    private array $colorSlugs = array();

    /** @return array{hex:array<string,string>,slug:array<string,int>,block:array<string,string>} */
    private function colorIndex(): array
    {
        if (null !== $this->colorIndex) return $this->colorIndex;
        $hex = $slug = $block = array();
        foreach ($this->colors as $index => $color) {
            $value = CssColor::hex($color['color']);
            if (!isset($slug[$color['slug']])) $slug[$color['slug']] = $index;
            if (null === $value) continue;
            // Block-only colours bind only the literal they came from
            // (blockColorSlug()), never stylesheets, classes or elements.
            if (self::BLOCK_COLOR_ROLE === $color['role']) {
                if (!isset($block[$value])) $block[$value] = $color['slug'];
            } elseif (!isset($hex[$value])) $hex[$value] = $color['slug'];
        }
        return $this->colorIndex = array('hex' => $hex, 'slug' => $slug, 'block' => $block);
    }

    /**
     * Slug of the block-only token (withBlockColors()) that paints exactly
     * this opaque colour, or null.
     */
    public function blockColorSlug(string $value): ?string
    {
        if (!CssColor::opaque($value)) return null;
        $hex = CssColor::hex($value);
        return null === $hex ? null : ($this->colorIndex()['block'][$hex] ?? null);
    }

    /**
     * Rendered design usage of every compiled document, keyed by source path.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return array<string,array<string,mixed>>
     */
    public static function usageBySource(array $documents): array
    {
        $usage = array();
        foreach ($documents as $document) {
            if (is_array($document['design_usage'] ?? null) && is_string($document['source_path'] ?? null)) $usage[$document['source_path']] = $document['design_usage'];
        }
        return $usage;
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
        $rootPx = $renderedRoots = $sourceRoles = array();
        foreach ($usageBySource as $usage) {
            $px = (float) ($usage['root_font_size_px'] ?? 16);
            $renderedRoots[isset($usage['root_font_size_px']) || !($usage['root_font_size_authored'] ?? false) ? CssLength::format($px) : 'unknown'] = true;
            // Older usage payloads lack the evidence flag; retain their explicit
            // root values, but never let a collector's initial-value fallback vote.
            if (($usage['root_font_size_authored'] ?? true) && isset($usage['root_font_size_px']) && $px > 0) {
                $rootPx[CssLength::format($px)] = ($rootPx[CssLength::format($px)] ?? 0) + 1;
            }
            foreach ($usage['root_color_tokens'] ?? array() as $name => $value) {
                $color = self::opaqueHex($value);
                if (null !== $color) $sourceRoles[$name][$color] = ($sourceRoles[$name][$color] ?? 0) + 1;
            }
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
        $rootFontPx = array() === $rootPx ? 16.0 : (float) rtrim((string) array_key_first($rootPx), 'px');

        $root = self::unanimousRoot($usageBySource);
        // An explicit pixel size replaces the UA medium keyword, which also
        // changes bare monospace sizing. Require root author evidence on every
        // document before applying the rendered size site-wide.
        foreach ($usageBySource as $usage) {
            if (true !== ($usage['root']['font_size_authored'] ?? false)) {
                unset($root['font_size']);
                break;
            }
        }
        $nearShades = array();
        $colors = self::palette($prominence, $textByColor, $backgroundByColor, $ctaByColor, $textOnBackground, $root, $sourceRoles, $nearShades);
        foreach ($colors as &$color) $color['name'] = self::sourceName($sourceRoles, $color['color']) ?? $color['name'];
        unset($color);
        $literalColors = array_keys(array_diff_key($prominence, array_flip(array_column($colors, 'color'))));
        usort($literalColors, static fn(string $a, string $b): int => ($prominence[$b] <=> $prominence[$a]) ?: strcmp($a, $b));
        foreach ($usageBySource as $usage) foreach ($usage['heading_typography'] ?? array() as $heading) {
            if (($heading['size_px'] ?? 0) > 0) $headingSizes[CssLength::format((float) $heading['size_px'])] = 1;
        }
        $buttonLook = self::dominantButtonLook($usageBySource);
        if (isset($buttonLook['font_size'])) $headingSizes[$buttonLook['font_size']] = 1;
        // A rem preset resolves against whichever root the rendering document
        // has. Spell presets in rem only when every document renders the one
        // root they were measured under; otherwise their pixel value.
        $sharedRoot = array(CssLength::format($rootFontPx) => true) === $renderedRoots;
        $fontSizes = self::fontSizes($sizeWeights, $bodySizeWeights, $sharedRoot ? $rootFontPx : 16.0, $headingSizes);
        $fontFamilies = self::fontFamilies($familyWeights, $familyStacks, $headingFamilyWeights, $bodyFamilyWeights, self::familyKey((string) ($root['font_family'] ?? '')), self::unanimousHeadingFamily($usageBySource), $headingFamilyProminence);
        $spacing = self::spacing($spacingRows);
        $lengthNames = array();
        foreach ($usageBySource as $usage) foreach ($usage['root_design_tokens'] ?? array() as $name => $value) {
            $px = CssLength::toPx($value, $rootFontPx);
            if (null !== $px) $lengthNames[$name][CssLength::format($px)] = ($lengthNames[$name][CssLength::format($px)] ?? 0) + 1;
        }
        foreach ($fontSizes as &$size) $size['name'] = self::sourceName($lengthNames, CssLength::format($size['px'])) ?? $size['name'];
        unset($size);
        foreach ($spacing as &$step) $step['name'] = self::sourceName($lengthNames, CssLength::format($step['px'])) ?? $step['name'];
        unset($step);
        $layout = self::layout($widthRows);

        $system = new self(array_slice($colors, 0, self::PRIMARY_COLORS), $fontSizes, $fontFamilies, $spacing, $layout, array(), array(), $rootFontPx > 0 ? $rootFontPx : 16.0, count($usageBySource), $literalColors, array(), $nearShades);
        // Observed translucent paint gets a preset even when its opaque RGB
        // is not painted anywhere. Prefer a derived tint when an exact base
        // exists; otherwise retain the source value, including its alpha.
        // Core can then serialize preset classes and show an editable value.
        $tints = array();
        foreach ($usageBySource as $usage) {
            $values = array_column($usage['text'] ?? array(), 'color');
            array_push($values, ...array_column($usage['backgrounds'] ?? array(), 'color'));
            foreach ($usage['class_styles'] ?? array() as $row) {
                foreach (array('color', 'background-color') as $property) {
                    foreach ($row['properties'][$property] ?? array() as $observation) {
                        array_push($values, ...explode(';', $observation));
                    }
                }
            }
            foreach ($values as $value) {
                $tint = $system->translucentColor($value);
                if (null === $tint) {
                    $parsed = CssColor::parse($value);
                    $hex = CssColor::hex($value);
                    if (null !== $parsed && $parsed[3] > 0 && $parsed[3] < 1 && null !== $hex) {
                        // Gutenberg kebab-cases preset slugs, splitting digit/letter
                        // boundaries. Encode the RGBA bytes with letters only so
                        // its save() class agrees with the compiler's class.
                        $slug = 'alpha-' . strtr(ltrim($hex, '#'), '0123456789abcdef', 'abcdefghijklmnop');
                        $tints[$slug] = array('slug' => $slug, 'name' => 'Translucent ' . self::colorName($value), 'color' => $value, 'role' => $slug, 'weight' => 0);
                    }
                    continue;
                }
                $slug = $tint['slug'] . '-alpha-' . $tint['percent'];
                $tints[$slug] = array('slug' => $slug, 'name' => 'Translucent ' . self::colorName((string) $system->color($tint['slug'])) . ' ' . $tint['percent'] . '%', 'color' => 'color-mix(in srgb, var(--wp--preset--color--' . $tint['slug'] . ') ' . $tint['percent'] . '%, transparent)', 'role' => $slug, 'weight' => 0);
            }
        }
        ksort($tints, SORT_STRING);
        array_push($colors, ...array_values($tints));
        foreach (array('background' => 'colorSlug', 'color' => 'colorSlug', 'font_family' => 'fontFamilySlug', 'font_size' => 'fontSizeSlug') as $field => $method) {
            if (isset($buttonLook[$field]) && null === $system->{$method}($buttonLook[$field])) unset($buttonLook[$field]);
        }
        return new self($colors, $fontSizes, $fontFamilies, $spacing, $layout, $root, array_replace(self::unanimousElements($usageBySource, $system), array() === $buttonLook ? array() : array('button' => $buttonLook)), $system->rootPx, $system->documentCount, $literalColors, array(), $nearShades);
    }

    /** Preserve the primary palette's stylesheet scope when adding block-only tokens. */
    public function stylesheetColorSlug(string $value): ?string
    {
        $slug = $this->colorSlug($value);
        $index = null === $slug ? null : ($this->colorIndex()['slug'][$slug] ?? null);
        if (null === $index) return null;
        return $index < self::PRIMARY_COLORS || 0 === $this->colors[$index]['weight'] ? $slug : null;
    }

    /**
     * A near shade the palette omits, restated as a CSS relative colour
     * derived from its family's representative preset, or null. At the
     * default palette it renders the exact source shade (the channel
     * offsets are the source minus the representative, alpha kept); when a
     * site owner edits the representative the shade moves with it:
     * `#f7f7f4` beside `base` #ffffff is
     * `rgb(from var(--wp--preset--color--base) calc(r - 8) calc(g - 8) calc(b - 11))`.
     *
     * Stylesheets only: safecss strips relative colours from block inline
     * styles, so callers emit the source literal first as the fallback for
     * engines without relative colour support.
     */
    public function nearShadeColor(string $value): ?string
    {
        $color = CssColor::parse($value);
        if (null === $color || $color[3] <= 0.0) return null;
        $rgb = array_map(static fn(float $channel): int => (int) round($channel), array_slice($color, 0, 3));
        $hex = vsprintf('#%02x%02x%02x', $rgb);
        $representative = $this->nearShades[$hex] ?? null;
        $slug = null === $representative ? null : $this->stylesheetColorSlug($representative);
        $base = null === $slug ? null : CssColor::parse($representative);
        if (null === $base) return null;
        $channels = array();
        foreach (array('r', 'g', 'b') as $i => $name) {
            $offset = $rgb[$i] - (int) round($base[$i]);
            $channels[] = 0 === $offset ? $name : 'calc(' . $name . ($offset > 0 ? ' + ' : ' - ') . abs($offset) . ')';
        }
        $alpha = $color[3] < 1.0 ? ' / ' . rtrim(rtrim(sprintf('%.4F', $color[3]), '0'), '.') : '';
        return 'rgb(from var(--wp--preset--color--' . $slug . ') ' . implode(' ', $channels) . $alpha . ')';
    }

    /**
     * Slug of the token whose value is exactly this colour, or null. The
     * one colour binding rule: block attributes, stylesheet bindings and
     * element styles all bind through this method, and only to a token
     * that paints the same 8-bit colour, so binding never changes what
     * renders. A near shade the palette omits binds nothing here; stylesheets
     * derive it from its representative (nearShadeColor()). Observed
     * translucent colours bind to derived tint presets
     * when possible, otherwise to exact standalone alpha presets.
     */
    public function colorSlug(string $value): ?string
    {
        if (array_key_exists($value, $this->colorSlugs)) return $this->colorSlugs[$value];
        if (count($this->colorSlugs) >= self::COLOR_SLUG_MEMO_LIMIT) $this->colorSlugs = array();
        return $this->colorSlugs[$value] = $this->resolveColorSlug($value);
    }

    private function resolveColorSlug(string $value): ?string
    {
        $index = $this->colorIndex();
        if (!CssColor::opaque($value)) {
            $tint = $this->translucentColor($value);
            if (null === $tint) {
                $hex = CssColor::hex($value);
                return null === $hex ? null : ($index['hex'][$hex] ?? null);
            }
            $slug = $tint['slug'] . '-alpha-' . $tint['percent'];
            return isset($index['slug'][$slug]) ? $slug : null;
        }
        $hex = CssColor::hex($value);
        return null === $hex ? null : ($index['hex'][$hex] ?? null);
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
        if (round(abs($color[3] * 100 - $percent), 6) > 0.2) return null;
        $slug = $this->colorSlug(sprintf('#%02x%02x%02x', (int) round($color[0]), (int) round($color[1]), (int) round($color[2])));
        return null === $slug ? null : array('slug' => $slug, 'percent' => $percent);
    }

    /**
     * The system without the button element styles some `core/button` in
     * the markup would take from them. An element style reaches every
     * button that does not carry its own value; the usage it comes from
     * only covers buttons that render a fill, so an outlined button that
     * converts to `core/button` would otherwise be painted. A button value
     * stays only when every button block owns that field. Dominant look
     * fields are checked independently, including shape and spacing; matching
     * inherited typography and core's outline fill also establish ownership.
     *
     * A button restyled from box-tree evidence carries its look in a carrier
     * rule on its link instead of block attributes. That rule outranks the
     * element style, so each field its unconditional declarations set is
     * owned exactly as the matching attribute would own it.
     *
     * @param list<string> $markups Every page's block markup.
     * @param list<string> $stylesheets Stylesheets the pages load, read for button link carriers.
     */
    public function withButtonElementsOwnedBy(array $markups, array $stylesheets = array()): self
    {
        if (!isset($this->elements['button'])) return $this;
        $dominant = isset($this->elements['button']['padding']) || isset($this->elements['button']['radius']);
        $owned = array('color' => true, 'background' => true, 'font_family' => true, 'font_size' => true, 'font_weight' => true);
        $carriers = self::buttonLinkCarriers($stylesheets);
        foreach ($markups as $markup) {
            if (!preg_match_all('/<!-- (\/)?wp:(buttons?)(?: (\{.*?\}))? \/?-->/s', $markup, $blocks, PREG_SET_ORDER)) continue;
            // Class tokens of each open core/buttons, innermost last.
            $groups = array();
            foreach ($blocks as $block) {
                $json = $block[3] ?? '';
                $attributes = '' === $json ? array() : json_decode($json, true);
                if (!is_array($attributes)) $attributes = array();
                if ('buttons' === $block[2]) {
                    if ('/' === $block[1]) array_pop($groups);
                    else $groups[] = self::classTokens($attributes);
                    continue;
                }
                if ('/' === $block[1]) continue;
                $carrier = self::buttonLinkCarrierProperties($carriers, self::classTokens($attributes), array() === $groups ? array() : $groups[count($groups) - 1]);
                $declares = static function (array ...$alternatives) use ($carrier): bool {
                    foreach ($alternatives as $properties) if (array() === array_diff($properties, $carrier)) return true;
                    return false;
                };
                $style = ArrayField::array($attributes, 'style');
                $carried = array(
                    'color' => isset($attributes['textColor']) || isset($style['color']['text']),
                    'background' => isset($attributes['backgroundColor']) || isset($attributes['gradient']) || isset($style['color']['background']) || isset($style['color']['gradient']),
                    'font_family' => isset($attributes['fontFamily']) || isset($style['typography']['fontFamily']),
                    'font_size' => isset($attributes['fontSize']) || isset($style['typography']['fontSize']),
                    'font_weight' => isset($style['typography']['fontWeight']),
                );
                $carried['color'] = $carried['color'] || $declares(array('color'));
                $carried['background'] = $carried['background'] || $declares(array('background-color'), array('background'));
                $carried['font_family'] = $carried['font_family'] || $declares(array('font-family'), array('font'));
                $carried['font_size'] = $carried['font_size'] || $declares(array('font-size'), array('font'));
                $carried['font_weight'] = $carried['font_weight'] || $declares(array('font-weight'), array('font'));
                if ($dominant) {
                    // Missing ownership vetoes only this field, not the rest of
                    // the dominant look. Matching inherited defaults are safe.
                    $carried['font_family'] = $carried['font_family'] || self::familyKey((string) ($this->root['font_family'] ?? '')) === self::familyKey($this->elements['button']['font_family'] ?? '');
                    $carried['font_weight'] = $carried['font_weight'] || '400' === ($this->elements['button']['font_weight'] ?? null);
                    $carried['background'] = $carried['background'] || str_contains((string) ($attributes['className'] ?? ''), 'is-style-outline');
                    $carried['radius'] = isset($style['border']['radius']) || $declares(array('border-radius'), array('border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius'));
                    $carried['padding'] = isset($style['spacing']['padding']) || $declares(array('padding'), array('padding-top', 'padding-right', 'padding-bottom', 'padding-left'));
                    $carried['text_transform'] = isset($style['typography']['textTransform']) || $declares(array('text-transform'));
                    $carried['letter_spacing'] = isset($style['typography']['letterSpacing']) || $declares(array('letter-spacing'));
                }
                foreach ($carried as $field => $present) if (!$present) $owned[$field] = false;
            }
        }
        $button = $this->elements['button'];
        foreach ($owned as $field => $isOwned) if (!$isOwned) unset($button[$field]);
        foreach (array('hover', 'focus', 'focus-visible', 'focus-within', 'active') as $state) {
            if (!$owned['color']) unset($button[$state . '_color']);
            if (!$owned['background']) unset($button[$state . '_background']);
        }
        $elements = $this->elements;
        if (array() === $button) unset($elements['button']); else $elements['button'] = $button;
        return new self($this->colors, $this->fontSizes, $this->fontFamilies, $this->spacing, $this->layout, $this->root, $elements, $this->rootPx, $this->documentCount, $this->literalColors, $this->buttonVariation, $this->nearShades);
    }

    /** @return list<string> */
    private static function classTokens(array $attributes): array
    {
        return array_values(array_filter(preg_split('/\s+/', is_string($attributes['className'] ?? null) ? $attributes['className'] : '') ?: array(), static fn(string $class): bool => '' !== $class));
    }

    /**
     * Unconditional rules that style a button link through its block's class
     * (`.c.c>a.wp-block-button__link`) or its core/buttons' class
     * (`.c.c>.wp-block-button>a.wp-block-button__link`), the two shapes a
     * box-tree carrier takes on button blocks. Media, state and pseudo rules
     * only refine a look, so they do not establish ownership.
     *
     * @param list<string> $stylesheets
     * @return list<array{classes: list<string>, group: bool, properties: list<string>}>
     */
    private static function buttonLinkCarriers(array $stylesheets): array
    {
        $carriers = array();
        foreach ($stylesheets as $css) {
            if (!str_contains($css, 'wp-block-button__link')) continue;
            $tree = new CssRuleTree($css);
            foreach ($tree->rules() as $rule) {
                if ($rule['boundary'] === $rule['end']) continue;
                $prelude = trim((string) preg_replace('#/\*.*?\*/#s', '', substr($css, $rule['start'], $rule['boundary'] - $rule['start'])));
                if ('' === $prelude || '@' === $prelude[0] || !str_contains($prelude, 'wp-block-button__link')) continue;
                $properties = array();
                foreach (CssValueSplitter::splitTopLevel(substr($css, $rule['boundary'] + 1, $rule['end'] - $rule['boundary'] - 1), array(';')) as $declaration) {
                    $name = strtolower(trim(explode(':', $declaration, 2)[0]));
                    if ('' !== $name && str_contains($declaration, ':')) $properties[$name] = true;
                }
                if (array() === $properties) continue;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    if (!preg_match('/^((?:\.[A-Za-z_][\w-]*)+)\s*>\s*(\.wp-block-button\s*>\s*)?a\.wp-block-button__link$/', trim($selector), $match)) continue;
                    $carriers[] = array('classes' => array_values(array_unique(explode('.', substr($match[1], 1)))), 'group' => '' !== ($match[2] ?? ''), 'properties' => array_keys($properties));
                }
            }
        }
        return $carriers;
    }

    /**
     * CSS properties the carriers declare on one button's link.
     *
     * @param list<array{classes: list<string>, group: bool, properties: list<string>}> $carriers
     * @param list<string> $buttonClasses
     * @param list<string> $groupClasses The enclosing core/buttons' classes.
     * @return list<string>
     */
    private static function buttonLinkCarrierProperties(array $carriers, array $buttonClasses, array $groupClasses): array
    {
        $properties = array();
        foreach ($carriers as $carrier) {
            if (array() !== array_diff($carrier['classes'], $carrier['group'] ? $groupClasses : $buttonClasses)) continue;
            foreach ($carrier['properties'] as $property) $properties[$property] = true;
        }
        return array_keys($properties);
    }

    /**
     * The system with an exact preset for every literal font size and font
     * family a block carries in `style.typography` that no preset renders.
     *
     * Usage reads the static author cascade, but a block can also carry the
     * typography the browser rendered for its source node (box-tree evidence
     * of a font the static cascade cannot resolve, such as a size computed
     * from a viewport scaling factor). Those values are rendered text all
     * the same, and without a token they stay literals outside the editable
     * design system. Like heading sizes, they are additive: the established
     * scale, its names and its roles are unchanged, and each new token is the
     * exact value, so binding never changes a rendered font.
     *
     * A fluid size (`clamp(42px,6.5vw,84px)`) built only from absolute and
     * viewport lengths renders the same for every block at a given viewport,
     * whatever its parent, so it is one exact token too (fluidFontSize()).
     * Its preset has no single pixel size (`px` 0), so an absolute literal
     * never resolves to it through fontSizeSlug(); only the identical value
     * binds to it.
     *
     * @param list<string> $markups Every page's block markup.
     */
    public function withBlockTypography(array $markups): self
    {
        $sizes = $families = $fluid = array();
        foreach ($markups as $markup) {
            if (!preg_match_all('/<!-- wp:[a-z0-9\/-]+ (\{.*?\}) \/?-->/s', $markup, $matches)) continue;
            foreach ($matches[1] as $json) {
                $attributes = json_decode($json, true);
                $typography = is_array($attributes) && is_array($attributes['style']['typography'] ?? null) ? $attributes['style']['typography'] : array();
                $size = is_string($typography['fontSize'] ?? null) ? trim($typography['fontSize']) : '';
                // Only absolute lengths: a relative size renders differently
                // under each parent, so it has no single exact token.
                if (1 === preg_match('/^\d*\.?\d+(?:px|rem)$/i', $size) && null === $this->fontSizeSlug($size)) {
                    $px = CssLength::toPx($size, $this->rootPx);
                    if (null !== $px && $px > 0) {
                        $pixel = CssLength::format($px);
                        if (!isset($sizes[$pixel]) || strcmp($size, $sizes[$pixel]) < 0) $sizes[$pixel] = $size;
                    }
                } elseif (null !== ($range = $this->fluidFontSize($size)) && !in_array($size, array_column($this->fontSizes, 'size'), true)) {
                    $fluid[$size] = $range;
                }
                $stack = is_string($typography['fontFamily'] ?? null) ? trim($typography['fontFamily']) : '';
                $key = self::familyKey($stack);
                if ('' === $key || str_contains($stack, 'var(') || in_array($key, self::CSS_WIDE_KEYWORDS, true) || in_array($key, self::GENERIC_FAMILIES, true) || null !== $this->fontFamilySlug($stack)) continue;
                $families[$key][$stack] = ($families[$key][$stack] ?? 0) + 1;
            }
        }
        if (array() === $sizes && array() === $families && array() === $fluid) return $this;
        $fontSizes = $this->fontSizes;
        $used = array_fill_keys(array_column($fontSizes, 'slug'), true);
        uksort($sizes, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
        foreach ($sizes as $px => $value) {
            // Kebab-case splits letters from digits (see fontSizes()).
            $base = self::presetSlug('size-' . rtrim((string) $px, 'px'));
            $slug = $base;
            for ($suffix = 2; isset($used[$slug]); $suffix++) $slug = $base . '-' . $suffix;
            $used[$slug] = true;
            $fontSizes[] = array('slug' => $slug, 'name' => self::sizeName((float) $px, $this->rootPx), 'size' => $value, 'px' => (float) $px, 'weight' => 0);
        }
        uksort($fluid, static fn(string $a, string $b): int => ($fluid[$a][1] <=> $fluid[$b][1]) ?: ($fluid[$a][0] <=> $fluid[$b][0]) ?: strcmp($a, $b));
        foreach ($fluid as $value => [$min, $max]) {
            $range = CssLength::format($min) === CssLength::format($max) ? rtrim(CssLength::format($max), 'px') : rtrim(CssLength::format($min), 'px') . '-' . rtrim(CssLength::format($max), 'px');
            $base = self::presetSlug('fluid-' . $range);
            $slug = $base;
            for ($suffix = 2; isset($used[$slug]); $suffix++) $slug = $base . '-' . $suffix;
            $used[$slug] = true;
            $name = 'Fluid ' . (string) preg_replace('/\s*\([^)]*\)$/', '', self::sizeName($max, $this->rootPx)) . ' (' . $range . 'px)';
            $fontSizes[] = array('slug' => $slug, 'name' => $name, 'size' => $value, 'px' => 0.0, 'weight' => 0);
        }
        $fontFamilies = $this->fontFamilies;
        $used = array_fill_keys(array_merge(array('body', 'heading'), array_column($fontFamilies, 'slug')), true);
        ksort($families, SORT_STRING);
        foreach ($families as $key => $choices) {
            // The binder identifies a family by its primary name. Different
            // fallback stacks cannot share an exact supplemental token.
            if (count($choices) > 1) continue;
            uksort($choices, static fn(string $a, string $b): int => ($choices[$b] <=> $choices[$a]) ?: strcmp($a, $b));
            $stack = (string) array_key_first($choices);
            $name = self::familyName($stack);
            $base = self::presetSlug($name);
            if ('' === $base) $base = 'family';
            $slug = $base;
            for ($suffix = 2; isset($used[$slug]); $suffix++) $slug = $base . '-' . $suffix;
            $used[$slug] = true;
            $fontFamilies[] = array('slug' => $slug, 'name' => $name, 'fontFamily' => $stack, 'key' => (string) $key, 'role' => 'family', 'weight' => 0);
        }
        return new self($this->colors, $fontSizes, $fontFamilies, $this->spacing, $this->layout, $this->root, $this->elements, $this->rootPx, $this->documentCount, $this->literalColors, $this->buttonVariation, $this->nearShades);
    }

    /**
     * The smallest and largest absolute bound, in pixels, of a fluid font
     * size: a `clamp()`, `min()` or `max()` whose terms are absolute lengths
     * (`px`, `rem`), viewport lengths (`vw`, `vh`, `vmin`, `vmax` and their
     * `s`/`l`/`d` variants) and plain numbers. Null for anything else: an
     * `em`, `%`, `ch` or `var()` term renders differently under each parent.
     *
     * @return array{0:float,1:float}|null
     */
    private function fluidFontSize(string $value): ?array
    {
        if (1 !== preg_match('/^(?:clamp|min|max)\(.*\)$/is', $value)) return null;
        $terms = (string) preg_replace('/\b(?:clamp|min|max|calc)\(|[(),+*\/\s]|(?<![\d.])-/i', ' ', $value);
        $absolute = array();
        foreach (preg_split('/\s+/', trim($terms)) ?: array() as $term) {
            if ('' === $term) continue;
            if (1 !== preg_match('/^(?:\d+\.?\d*|\.\d+)(px|rem|[sld]?v(?:w|h|i|b|min|max))?$/i', $term, $match)) return null;
            $unit = strtolower($match[1] ?? '');
            if ('px' === $unit || 'rem' === $unit) $absolute[] = (float) CssLength::toPx($term, $this->rootPx);
        }
        if (array() === $absolute || max($absolute) <= 0) return null;
        // A clamp() names its own bounds; otherwise the absolute terms span it.
        if (1 === preg_match('/^clamp\(\s*([^(),]+?)\s*,.*,\s*([^(),]+?)\s*\)$/is', $value, $bounds)) {
            $low = CssLength::toPx($bounds[1], $this->rootPx);
            $high = CssLength::toPx($bounds[2], $this->rootPx);
            if (null !== $low && null !== $high && $low > 0 && $high >= $low) return array($low, $high);
        }
        return array(min($absolute), max($absolute));
    }

    /**
     * The system with exact tokens for distinct opaque literal colours a block
     * carries as its `style.color` text or background. A near shade of an
     * existing preset (within COLOR_CONSOLIDATION_DISTANCE) does not re-enter
     * the palette and keeps its exact literal on the block: safecss strips a
     * relative colour from a block's inline style, and a preset attribute
     * would repaint it. It is recorded in nearShades so stylesheet rules
     * painting it derive it from that preset (nearShadeColor()).
     *
     * Like withBlockTypography(), this covers the colour the browser rendered
     * for a block's source node (box-tree evidence: a computed `rgb(0, 0, 0)`
     * or `color(srgb …)` the static cascade cannot resolve). Those colours are
     * painted all the same, and without a token they stay literals outside the
     * editable palette. Each new token (`accent-N` after the usage-ranked
     * ones, most used first) is the exact 8-bit colour, so binding never
     * changes what renders. It carries BLOCK_COLOR_ROLE: it binds only that
     * block literal, inline and in place (blockColorSlug()), never a
     * stylesheet, a class-driven value or an element style, which keep the
     * cascade the usage-ranked palette established.
     *
     * @param list<string> $markups Every page's block markup.
     */
    public function withBlockColors(array $markups): self
    {
        $counts = array();
        foreach ($markups as $markup) {
            if (!preg_match_all('/<!-- wp:[a-z0-9\/-]+ (\{.*?\}) \/?-->/s', $markup, $matches)) continue;
            foreach ($matches[1] as $json) {
                $attributes = json_decode($json, true);
                $style = is_array($attributes) && is_array($attributes['style'] ?? null) ? $attributes['style'] : array();
                foreach (array($style['color']['text'] ?? null, $style['color']['background'] ?? null) as $value) {
                    if (!is_string($value) || str_contains($value, 'var') || !CssColor::opaque($value) || null !== $this->colorSlug($value)) continue;
                    $hex = (string) CssColor::hex($value);
                    $counts[$hex] = ($counts[$hex] ?? 0) + 1;
                }
            }
        }
        $known = array_flip(array_keys($this->colorIndex()['block']));
        $counts = array_diff_key($counts, $known);
        if (array() === $counts) return $this;
        uksort($counts, static fn(string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));
        $used = array_fill_keys(array_column($this->colors, 'slug'), true);
        $accent = 0;
        $colors = $this->colors;
        $nearShades = $this->nearShades;
        foreach (array_keys($counts) as $hex) {
            // A near shade keeps its block literal rather than re-entering
            // the palette; stylesheets derive it from the nearest preset.
            do $slug = 'accent-' . (++$accent); while (isset($used[$slug]));
            $used[$slug] = true;
            $near = $this->nearestColor($hex);
            if (null !== $near) {
                $nearShades[$hex] ??= $near;
                continue;
            }
            $colors[] = array('slug' => $slug, 'name' => self::colorName($hex), 'color' => $hex, 'role' => self::BLOCK_COLOR_ROLE, 'weight' => 0);
        }
        ksort($nearShades, SORT_STRING);
        return new self($colors, $this->fontSizes, $this->fontFamilies, $this->spacing, $this->layout, $this->root, $this->elements, $this->rootPx, $this->documentCount, $this->literalColors, $this->buttonVariation, $nearShades);
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
        return new self($this->colors, $this->fontSizes, $this->fontFamilies, $this->spacing, $this->layout, $this->root, $elements, $this->rootPx, $this->documentCount, $this->literalColors, $this->buttonVariation, $this->nearShades);
    }

    public function buttonVariationSlug(): ?string
    {
        return array() === $this->buttonVariation ? null : ($this->colorSlug($this->buttonVariation['background']) ?? 'primary');
    }

    /** A palette colour's value by slug, or null. */
    public function color(string $slug): ?string
    {
        $index = $this->colorIndex()['slug'][$slug] ?? null;
        return null === $index ? null : $this->colors[$index]['color'];
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

    /** Expand button padding and bind each side exactly as block spacing binds. */
    public function buttonPadding(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: array();
        $values = array($parts[0], $parts[1] ?? $parts[0], $parts[2] ?? $parts[0], $parts[3] ?? $parts[1] ?? $parts[0]);
        $padding = array();
        foreach (array('top', 'right', 'bottom', 'left') as $index => $side) {
            $slug = $this->spacingSlug($values[$index]);
            $padding[$side] = null === $slug ? $values[$index] : 'var:preset|spacing|' . $slug;
        }
        return $padding;
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
            'near_shades' => $this->nearShades,
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
     * - `restyle_coverage`: of the text, background and border colours rendered on
     *   block-level content, the share that come from a preset reference,
     *   through the block's attributes, its classes' stylesheet rules, an
     *   ancestor, or the root styles; see RestyleCoverage. These follow a
     *   palette edit in Global Styles. A shared template part counts once
     *   per page, as a reader meets it on every route; engine templates count
     *   once. `restyle_text_elements`,
     *   `restyle_text_preset`, `restyle_backgrounds` and
     *   `restyle_backgrounds_preset`, `restyle_borders` and
     *   `restyle_borders_preset` are the counts behind it.
     * - `refusal_reasons`: text and background colour values binding left
     *   as literals, by reason (see PresetBinding::REFUSAL_REASONS), and
     *   `refused_value_count` their total. Content that should not follow
     *   the palette (a "before" mock-up in its own colours) shows up here
     *   rather than being forced onto a token. A class-driven value counted
     *   here can still follow the palette through a bound stylesheet rule,
     *   which `restyle_coverage` measures.
     * - `palette_size`: colours in the palette.
     * - `unnamed_preset_count`: presets labelled only by an index or raw value.
     * - `duplicate_preset_name_count`: colour, font-size and spacing presets
     *   whose display name repeats an earlier preset's in the same category.
     * - `literal_color_count`: painted opaque colours without a palette token
     *   (report `literal_colors`); zero when every usage colour is registered.
     *   Near shades among them keep their exact paint and stylesheets derive
     *   them from their family's representative (report `near_shades`).
     * - `literal_preset_color_count`: block colour attributes (text,
     *   background, border and its sides, link) that store a literal equal
     *   to a palette preset, so a palette edit skips them; zero when every
     *   such colour is bound (see PresetBinding).
     * - `unused_font_families`: primary families (the first name of a
     *   `font-family` stack, unquoted) the source stylesheets declare that no
     *   rendered text uses as its primary, so the theme does not offer them.
     *   Fallbacks later in a stack and `@font-face` descriptors are not
     *   declarations of a family in use.
     * - `theme_css_bytes`: CSS the theme ships, every stylesheet asset
     *   (author projections, per-page copies, shared chrome, engine support)
     *   plus inline block `style` attributes.
     * - `unique_source_css_bytes`: input artifact CSS files and inline
     *   <style> block contents, deduplicated by content hash before compilation.
     *   Compiled-site source_css_bytes supplies this total, not emitted assets.
     *   Without an explicit total, the bytes of the shipped author stylesheets.
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
        $preset = $literal = $literalPreset = 0;
        $inline = $engine = $shipped = 0;
        foreach ($markups as $markup) {
            if (preg_match_all('/<!-- wp:[a-z0-9\/-]+ (\{.*?\}) \/?-->/s', $markup, $matches)) {
                foreach ($matches[1] as $json) {
                    $attributes = json_decode($json, true);
                    if (!is_array($attributes)) continue;
                    foreach (array('textColor', 'backgroundColor', 'fontSize', 'fontFamily') as $key) if (is_string($attributes[$key] ?? null)) $preset++;
                    $style = ArrayField::array($attributes, 'style');
                    $values = array($style['color']['text'] ?? null, $style['color']['background'] ?? null, $style['typography']['fontSize'] ?? null, $style['typography']['fontFamily'] ?? null);
                    foreach (array('padding', 'margin') as $box) foreach ((array) ($style['spacing'][$box] ?? array()) as $value) $values[] = $value;
                    foreach ((array) ($style['spacing']['blockGap'] ?? array()) as $value) $values[] = $value;
                    $border = ArrayField::array($style, 'border');
                    $colors = array($style['color']['text'] ?? null, $style['color']['background'] ?? null, $border['color'] ?? null, $style['elements']['link']['color']['text'] ?? null);
                    foreach (array('top', 'right', 'bottom', 'left') as $side) $colors[] = $border[$side]['color'] ?? null;
                    foreach ($colors as $color) if (is_string($color) && !str_starts_with($color, 'var:') && null !== $this->colorSlug($color)) $literalPreset++;
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
            foreach (CssRuleAnalyzer::customProperties($asset['content']) as $declaration) $variables[$declaration['name']] ??= $declaration['value'];
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
            'restyle_borders' => $restyle['borders'],
            'restyle_borders_preset' => $restyle['borders_preset'],
            'preset_value_count' => $preset,
            'literal_value_count' => $literal,
            'refused_value_count' => array_sum($refusals),
            'refusal_reasons' => $refusals,
            'palette_size' => count($this->colors),
            'unnamed_preset_count' => $this->unnamedPresetCount(),
            'duplicate_preset_name_count' => $this->duplicatePresetNameCount(),
            'literal_color_count' => count($this->literalColors),
            'literal_preset_color_count' => $literalPreset,
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

    private function unnamedPresetCount(): int
    {
        $count = 0;
        foreach (array_merge($this->colors, $this->fontSizes, $this->fontFamilies, $this->spacing) as $preset) {
            $name = strtolower(trim($preset['name']));
            // Semantic roles, scale words and typeface names may equal their
            // slugs; numbered labels and raw values are not human names.
            if ('' === $name || 1 === preg_match('/^(?:(?:accent|size|heading|alpha)[ -]*)?[#\d][\da-f.%-]*(?:px|rem|em)?$/i', $name) || in_array($name, array_map('strtolower', array_filter(array($preset['color'] ?? null, $preset['size'] ?? null))), true)) ++$count;
        }
        return $count;
    }

    /** @param array<string,array<string,int>> $votes */
    private static function sourceName(array $votes, string $value): ?string
    {
        $names = array();
        foreach ($votes as $name => $values) {
            $label = ucwords(trim((string) preg_replace('/[-_]+/', ' ', ltrim($name, '-'))));
            if (isset($values[$value]) && 1 === preg_match('/[a-z]{3}/i', $label) && 1 !== preg_match('/^(?:Color|Accent|Size|Spacing|Font)\s*\d+$/i', $label)) $names[$label] = ($names[$label] ?? 0) + $values[$value];
        }
        $labels = array_keys($names);
        usort($labels, static fn(string $a, string $b): int => ($names[$b] <=> $names[$a]) ?: strcmp($a, $b));
        return $labels[0] ?? null;
    }

    /** Hex of the nearest opaque preset within COLOR_CONSOLIDATION_DISTANCE of $hex, or null. */
    private function nearestColor(string $hex): ?string
    {
        $nearest = null;
        $nearestDistance = INF;
        foreach ($this->colors as $color) {
            $value = CssColor::opaque($color['color']) ? CssColor::hex($color['color']) : null;
            $distance = null === $value ? null : CssColor::distance($hex, $value);
            if (null === $distance || $distance > self::COLOR_CONSOLIDATION_DISTANCE || $distance >= $nearestDistance) continue;
            $nearest = $value;
            $nearestDistance = $distance;
        }
        return $nearest;
    }

    private function duplicatePresetNameCount(): int
    {
        $count = 0;
        foreach (array($this->colors, $this->fontSizes, $this->spacing) as $presets) {
            $seen = array();
            foreach ($presets as $preset) {
                $key = strtolower(trim($preset['name']));
                if (isset($seen[$key])) ++$count;
                $seen[$key] = true;
            }
        }
        return $count;
    }

    /**
     * Hue family, HSL lightness and saturation of a colour. Near-neutral
     * colours are `Gray`, or `White` / `Black` at the extremes.
     *
     * @return array{family:string,lightness:float,saturation:float}
     */
    private static function colorTraits(string $value): array
    {
        [$r, $g, $b] = CssColor::parse($value) ?? array(0.0, 0.0, 0.0, 1.0);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $chroma = $max - $min;
        $lightness = ($max + $min) / 510;
        $saturation = $chroma <= 0 ? 0.0 : $chroma / max(1.0, 255 - abs($max + $min - 255));
        if ($chroma < 18) {
            $family = $lightness > 0.94 ? 'White' : ($lightness < 0.06 ? 'Black' : 'Gray');
            return array('family' => $family, 'lightness' => $lightness, 'saturation' => $saturation);
        }
        $hue = $max === $r ? 60 * fmod(($g - $b) / $chroma, 6) : ($max === $g ? 60 * (($b - $r) / $chroma + 2) : 60 * (($r - $g) / $chroma + 4));
        $hue = fmod($hue + 360, 360);
        $family = match (true) {
            $hue < 15, $hue >= 345 => 'Red',
            $hue < 50 => $lightness > 0.6 ? 'Sand' : 'Brown',
            $hue < 70 => 'Gold',
            $hue < 165 => 'Green',
            $hue < 200 => 'Teal',
            $hue < 260 => 'Blue',
            $hue < 295 => 'Purple',
            default => 'Rose',
        };
        return array('family' => $family, 'lightness' => $lightness, 'saturation' => $saturation);
    }

    private static function colorName(string $value): string
    {
        $traits = self::colorTraits($value);
        $lightness = $traits['lightness'];
        return match ($traits['family']) {
            'White', 'Black' => $traits['family'],
            'Gray' => ($lightness > 0.65 ? 'Light' : ($lightness < 0.35 ? 'Dark' : 'Mid')) . ' Gray',
            default => ($lightness < 0.3 ? 'Deep' : ($lightness > 0.75 ? 'Pale' : ($lightness > 0.55 ? 'Soft' : 'Rich'))) . ' ' . $traits['family'],
        };
    }

    /**
     * Gives every colour preset a distinct display name; slugs are kept.
     *
     * Source token names win. A derived name is a lightness word plus the
     * hue family (`Rich Brown`). When derived names collide, every derived
     * member of that family is renamed along one lightness ladder, darkest
     * to lightest, near each colour's absolute lightness (`Dusky Brown`,
     * `Mid Brown`, `Soft Brown`); colours of equal lightness are told apart
     * by saturation (`Muted`, `Rich`, `Vivid`). Translucent presets name
     * their final base colour and alpha. Only a collision none of this
     * resolves (two equal source names) adds the colour value.
     *
     * @param list<array{slug:string,name:string,color:string,role:string,weight:int}> $colors
     * @return list<array{slug:string,name:string,color:string,role:string,weight:int}>
     */
    private static function distinctColorNames(array $colors): array
    {
        $derived = array();
        foreach ($colors as $index => $color) {
            if (!CssColor::opaque($color['color'])) continue;
            $traits = self::colorTraits($color['color']);
            if (!self::isDerivedColorName($color['name'], $traits['family'])) continue;
            // Re-derive from the value so each pass ranks the whole family.
            $colors[$index]['name'] = self::colorName($color['color']);
            $derived[$index] = $traits;
        }
        $counts = array_count_values(array_map(static fn(array $color): string => strtolower($color['name']), $colors));
        $families = array();
        foreach ($derived as $index => $traits) {
            if ($counts[strtolower($colors[$index]['name'])] > 1) $families[$traits['family']] = true;
        }
        foreach (array_keys($families) as $family) {
            $members = array_filter($derived, static fn(array $traits): bool => $traits['family'] === $family);
            foreach (self::lightnessLadderNames($members, $family) as $index => $name) $colors[$index]['name'] = $name;
        }
        $names = array();
        foreach ($colors as $color) $names[$color['slug']] ??= $color['name'];
        foreach ($colors as $index => $color) {
            if (1 === preg_match('/^color-mix\(in srgb, var\(--wp--preset--color--([a-z0-9-]+)\) ([\d.]+)%, transparent\)$/', $color['color'], $match)) {
                if (isset($names[$match[1]])) $colors[$index]['name'] = 'Translucent ' . $names[$match[1]] . ' ' . $match[2] . '%';
            } elseif (!CssColor::opaque($color['color']) && null !== ($parsed = CssColor::parse($color['color'])) && $parsed[3] > 0) {
                $colors[$index]['name'] = 'Translucent ' . self::colorName($color['color']) . ' ' . round($parsed[3] * 100) . '%';
            }
        }
        return self::withDistinctNames($colors, static fn(array $color): array => array(strtoupper((string) (CssColor::hex($color['color']) ?? $color['color'])), $color['slug']));
    }

    /** True for a name this class derives from a colour of $family. */
    private static function isDerivedColorName(string $name, string $family): bool
    {
        $words = explode(' ', trim((string) preg_replace('/\s*\([^)]*\)$/', '', $name)));
        if (array_pop($words) !== $family) return false;
        $vocabulary = array_merge(array_keys(self::LIGHTNESS_WORDS), array('Rich', 'Muted', 'Vivid'));
        return array() === array_diff($words, $vocabulary);
    }

    /**
     * @param array<int,array{family:string,lightness:float,saturation:float}> $members
     * @return array<int,string> Index => name, or nothing when the family has more lightness tiers than words.
     */
    private static function lightnessLadderNames(array $members, string $family): array
    {
        $order = array_keys($members);
        usort($order, static fn(int $a, int $b): int => ($members[$a]['lightness'] <=> $members[$b]['lightness']) ?: ($members[$a]['saturation'] <=> $members[$b]['saturation']) ?: $a <=> $b);
        // Colours whose lightness differs by under 2% share a tier.
        $tiers = array();
        $previous = null;
        foreach ($order as $index) {
            $lightness = $members[$index]['lightness'];
            if (null === $previous || $lightness - $previous >= 0.02) $tiers[] = array();
            $tiers[count($tiers) - 1][] = $index;
            $previous = $lightness;
        }
        $words = array_keys(self::LIGHTNESS_WORDS);
        $bounds = array_values(self::LIGHTNESS_WORDS);
        $last = count($words) - 1;
        if (count($tiers) > count($words)) return array();
        // Each tier takes the word for its own lightness, shifted only as far
        // as needed to keep darker tiers on strictly darker words.
        $levels = array();
        foreach ($tiers as $tier => $indexes) {
            $level = 0;
            while ($level < $last && $members[$indexes[0]]['lightness'] >= $bounds[$level]) ++$level;
            $levels[$tier] = 0 === $tier ? $level : max($level, $levels[$tier - 1] + 1);
        }
        for ($tier = count($tiers) - 1; $tier >= 0; $tier--) {
            $levels[$tier] = min($levels[$tier], $tier === count($tiers) - 1 ? $last : $levels[$tier + 1] - 1);
        }
        $names = array();
        foreach ($tiers as $tier => $indexes) {
            usort($indexes, static fn(int $a, int $b): int => ($members[$a]['saturation'] <=> $members[$b]['saturation']) ?: $a <=> $b);
            $qualifiers = match (count($indexes)) {
                1 => array(''),
                2 => array('Muted', 'Vivid'),
                3 => array('Muted', 'Rich', 'Vivid'),
                default => array_fill(0, count($indexes), ''),
            };
            foreach ($indexes as $position => $index) {
                $names[$index] = implode(' ', array_filter(array($words[$levels[$tier]], $qualifiers[$position], $family)));
            }
        }
        return $names;
    }

    /**
     * Gives length presets distinct names: a repeated name adds the value,
     * then its slug.
     *
     * @param list<array{slug:string,name:string,size:string,px:float,weight:int}> $presets
     * @return list<array{slug:string,name:string,size:string,px:float,weight:int}>
     */
    private static function distinctLengthNames(array $presets): array
    {
        return self::withDistinctNames($presets, static fn(array $preset): array => array($preset['size'], ucwords(str_replace('-', ' ', $preset['slug']))));
    }

    /**
     * Keeps the first preset of each name and qualifies later repeats with
     * the first detail that is not already in the name and makes it unique.
     *
     * @template T of array{slug:string,name:string}
     * @param list<T> $presets
     * @param callable(T):list<string> $details
     * @return list<T>
     */
    private static function withDistinctNames(array $presets, callable $details): array
    {
        $taken = array();
        foreach ($presets as $preset) $taken[strtolower($preset['name'])] = true;
        $seen = array();
        foreach ($presets as $index => $preset) {
            $name = $preset['name'];
            if (isset($seen[strtolower($name)])) {
                $candidate = $name;
                foreach ($details($preset) as $detail) {
                    if (str_contains($name, $detail)) continue;
                    $candidate = $name . ' (' . $detail . ')';
                    if (!isset($taken[strtolower($candidate)])) break;
                }
                $name = $candidate;
                $taken[strtolower($name)] = true;
                $presets[$index]['name'] = $name;
            }
            $seen[strtolower($name)] = true;
        }
        return $presets;
    }

    private static function sizeName(float $px, float $rootPx): string
    {
        $ratio = $px / $rootPx;
        return match (true) {
            $ratio < 0.8 => 'Fine Print',
            $ratio < 1 => 'Small Text',
            $ratio < 1.2 => 'Body Text',
            $ratio < 1.6 => 'Lead Text',
            $ratio < 2.2 => 'Subheading',
            $ratio < 3 => 'Title',
            default => 'Display',
        } . ' (' . CssLength::format($px) . ')';
    }

    private static function spacingName(float $px): string
    {
        return match (true) {
            $px < 8 => 'Tight',
            $px < 16 => 'Compact',
            $px < 24 => 'Comfortable',
            $px < 48 => 'Roomy',
            default => 'Spacious',
        };
    }

    /**
     * @param array<string,int> $prominence Colour => prominence (see the class comment), which ranks clusters.
     * @param array<string,int> $text Colour => text area, which with $backgrounds chooses `base` and `contrast`.
     * @param array<string,int> $backgrounds
     * @param array<string,int> $cta
     * @param array<string,array<string,int>> $textOnBackground
     * @param array<string,string> $root Root values every document agrees on.
     * @param array<string,array<string,int>> $sourceRoles Root token => colour => document count.
     * @param array<string,string> $nearShades Set to each omitted painted shade's hex => its family representative's hex.
     * @return list<array{slug:string,name:string,color:string,role:string,weight:int}>
     */
    private static function palette(array $prominence, array $text, array $backgrounds, array $cta, array $textOnBackground, array $root = array(), array $sourceRoles = array(), array &$nearShades = array()): array
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

        $semantic = array();
        foreach (array('--background' => 'base', '--foreground' => 'contrast', '--primary' => 'primary', '--secondary' => 'secondary') as $name => $role) {
            $votes = array_intersect_key($sourceRoles[$name] ?? array(), $prominence);
            $candidates = array_keys($votes);
            usort($candidates, static fn(string $a, string $b): int => ($votes[$b] <=> $votes[$a]) ?: strcmp($a, $b));
            $color = $candidates[0] ?? null;
            if (null !== $color && !in_array($color, $semantic, true)) $semantic[$role] = $color;
        }
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
        foreach (array('primary', 'secondary') as $role) {
            if (isset($semantic[$role])) continue;
            foreach ($accents as $index) {
                if (isset($roles[$index]) || in_array($clusters[$index]['color'], $semantic, true)) continue;
                $roles[$index] = $role;
                break;
            }
        }
        $token = static fn(string $slug, string $color, int $weight): array => array(
            'slug' => self::presetSlug($slug),
            'name' => self::colorName($color),
            'color' => $color,
            'role' => preg_replace('/-\d+$/', '', $slug) ?? $slug,
            'weight' => $weight,
        );
        $palette = array();
        $taken = array_flip($semantic);
        foreach (array('base', 'contrast', 'primary', 'secondary') as $role) {
            $index = array_search($role, $roles, true);
            $color = $semantic[$role] ?? (false !== $index ? $clusters[$index]['color'] : null);
            if (null === $color || (!isset($semantic[$role]) && isset($taken[$color]))) continue;
            $palette[] = $token($role, $color, $prominence[$color]);
            $taken[$color] = $role;
        }
        // Only distinct family representatives enter the palette. A near
        // shade keeps its exact paint; stylesheets derive it from its
        // family's representative (nearShades), so it follows a palette
        // change with the rest of its family.
        $taken = array_flip(array_column($palette, 'color'));
        $rest = array_keys(array_diff_key($prominence, $taken));
        $isLead = static fn(string $color): int => $clusters[$memberOf[$color]]['color'] === $color ? 1 : 0;
        usort($rest, static fn(string $a, string $b): int => ($prominence[$b] <=> $prominence[$a]) ?: ($isLead($b) <=> $isLead($a)) ?: ($clusters[$memberOf[$b]]['weight'] <=> $clusters[$memberOf[$a]]['weight']) ?: strcmp($a, $b));
        $accent = 0;
        foreach ($rest as $color) {
            // Reserve the original slot even for an omitted shade: palette
            // membership may change, but surviving preset slugs must not.
            $slug = 'accent-' . (++$accent);
            if ($clusters[$memberOf[$color]]['color'] !== $color) continue;
            $palette[] = $token($slug, $color, $prominence[$color]);
        }
        // Each family is represented by its lead when that is a token, else
        // by its first token (a source role may name a non-lead member).
        $representatives = array();
        foreach ($palette as $entry) {
            $family = $memberOf[$entry['color']];
            if (!isset($representatives[$family]) || $clusters[$family]['color'] === $entry['color']) $representatives[$family] = $entry['color'];
        }
        $nearShades = array();
        foreach (array_keys(array_diff_key($prominence, array_flip(array_column($palette, 'color')))) as $color) {
            // A family member can sit further from the representative than the
            // bound (it joined through a nearer member); it then stays a
            // plain literal.
            $representative = $representatives[$memberOf[$color]] ?? null;
            $hex = CssColor::hex($color);
            if (null === $hex || null === $representative || (CssColor::distance($color, $representative) ?? INF) > self::COLOR_CONSOLIDATION_DISTANCE) continue;
            $nearShades[$hex] = (string) CssColor::hex($representative);
        }
        ksort($nearShades, SORT_STRING);
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
        // Each colour joins the first cluster (in creation order) whose lead
        // is within COLOR_TOLERANCE. Leads are bucketed on a Lab grid of that
        // cell size, so only the neighbouring cells can hold one: the first
        // match is the lowest matching index among them, not a scan of every
        // cluster.
        $clusters = $labs = array();
        $grid = new ColorGrid(self::COLOR_TOLERANCE);
        foreach ($colors as $color) {
            $lab = CssColor::lab($color);
            $first = null;
            if (null !== $lab) {
                foreach ($grid->near($lab) as $index) {
                    if ((null === $first || $index < $first) && CssColor::labDistance($lab, $labs[$index]) <= self::COLOR_TOLERANCE) $first = $index;
                }
            }
            if (null !== $first) {
                $clusters[$first]['weight'] += $weights[$color];
                $clusters[$first]['members'][] = $color;
                continue;
            }
            $index = count($clusters);
            $clusters[] = array('color' => $color, 'weight' => $weights[$color], 'members' => array($color));
            if (null !== $lab) {
                $labs[$index] = $lab;
                $grid->add($index, $lab);
            }
        }
        return $clusters;
    }

    /**
     * Merge each cluster into the nearest heavier kept cluster within
     * COLOR_CONSOLIDATION_DISTANCE, however much it is used, so a shade a
     * reader cannot tell from a heavier colour does not outrank a distinct
     * colour for a role. The merged cluster is one family: only its
     * representative becomes a token and stylesheets derive its other
     * members from that token (see palette()). Clusters
     * are visited heaviest first, so a cluster joins one that already holds
     * whatever merged into it; the heavier cluster keeps its colour.
     *
     * @param list<array{color:string,weight:int,members:list<string>}> $clusters Heaviest first.
     * @return list<array{color:string,weight:int,members:list<string>}>
     */
    public static function consolidateColors(array $clusters): array
    {
        // Kept colours never change, so they are bucketed on a Lab grid of
        // COLOR_CONSOLIDATION_DISTANCE cells: only neighbouring cells can hold
        // a candidate. Candidates are visited in kept order, so ties still
        // go to the earliest kept cluster.
        $kept = $labs = array();
        $grid = new ColorGrid(self::COLOR_CONSOLIDATION_DISTANCE);
        foreach ($clusters as $cluster) {
            $nearest = null;
            $nearestDistance = INF;
            $lab = CssColor::lab($cluster['color']);
            $candidates = null === $lab ? array() : $grid->near($lab);
            sort($candidates);
            foreach ($candidates as $index) {
                if ($kept[$index]['weight'] < $cluster['weight']) continue;
                $distance = CssColor::labDistance($lab, $labs[$index]);
                if ($distance <= self::COLOR_CONSOLIDATION_DISTANCE && $distance < $nearestDistance) {
                    $nearest = $index;
                    $nearestDistance = $distance;
                }
            }
            if (null === $nearest) {
                $index = count($kept);
                $kept[] = $cluster;
                if (null !== $lab) {
                    $labs[$index] = $lab;
                    $grid->add($index, $lab);
                }
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
            $slug = self::presetSlug(0 === $step ? 'medium' : ((abs($step) > 1 ? str_repeat('x', abs($step) - 1) . '-' : '') . ($step < 0 ? 'small' : 'large')));
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
            $slug = self::presetSlug('heading-' . rtrim((string) $px, 'px'));
            $sizes[] = array('slug' => $slug, 'name' => self::sizeName((float) $px, $rootPx), 'size' => self::lengthText((float) $px, $rootPx), 'px' => (float) $px, 'weight' => $weight);
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
            $slug = self::presetSlug((string) (($index + 2) * 10));
            $steps[] = array('slug' => $slug, 'name' => self::spacingName($cluster['px']) . ' (' . $value . ')', 'size' => $value, 'px' => $cluster['px'], 'weight' => $cluster['weight']);
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
     * Preset classes and variables must survive WordPress's kebab-case unchanged.
     * Lowercase first to preserve established name slugs, then separate both
     * letter/digit boundaries (including those inside opaque font identifiers).
     */
    private static function presetSlug(string $value): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
        return (string) preg_replace('/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/', '-', $slug);
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
                $base = self::presetSlug($name);
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
     * Root values (canvas background and body text typography) that every
     * document renders identically and no document varies conditionally.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,string>
     */
    private static function unanimousRoot(array $usageBySource): array
    {
        $values = array('background' => array(), 'color' => array(), 'font_family' => array(), 'font_size' => array(), 'line_height' => array());
        $authoredBackground = true;
        $conditional = array();
        foreach ($usageBySource as $usage) {
            $root = ArrayField::array($usage, 'root');
            // The body's cascaded size is not the html rem base or a text
            // run's class-owned size. Only shared root values can be defaults.
            $root['font_size'] = ($root['font_size_px'] ?? 0) > 0 ? CssLength::format((float) $root['font_size_px']) : '';
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
        foreach (array('font_size' => 'font-size', 'line_height' => 'line-height') as $field => $property) {
            $value = (string) array_key_first($values[$field]);
            if (1 === count($values[$field]) && '' !== $value && !isset($conditional[$property])) $root[$field] = $value;
        }
        return $root;
    }

    /**
     * Element styles (`styles.elements` in theme.json) from what every
     * element of a kind renders on every document: link colour and `:hover`
     * colour; button text and background colour, family, size and weight
     * and their `:hover` colours; heading colour, family and weight; and,
     * per heading level, colour, family, size and weight where that level
     * differs from the heading values. Element styles reach every element of
     * the kind. Heading families use a strict rendered majority only when
     * every minority heading has its own authored family declaration. Other elements and
     * per-level typography require unanimity. Colours, families and sizes
     * must be tokens (a colour within the JND of the token's own value). A `:hover` colour
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
        $values = $headingTypography = $inheritedHeadingFamilies = array();
        foreach ($usageBySource as $usage) foreach ($usage['heading_typography'] ?? array() as $row) {
            if (true === ($row['font_family_authored'] ?? false)) continue;
            $stack = (string) ($row['font_family'] ?? '');
            $slug = $system->fontFamilySlug($stack);
            foreach ($system->fontFamilies as $family) if ($family['slug'] === $slug) $stack = $family['fontFamily'];
            $inheritedHeadingFamilies[self::familyKey($stack) . "\n" . $stack] = true;
        }
        $conditional = array();
        foreach ($usageBySource as $usage) foreach ($usage['root']['conditional'] ?? array() as $family) $conditional[(string) $family] = true;
        // A media-conditional variant of the property makes the element render
        // something else under that condition, which an element style would
        // override: such a field has no single value.
        $families = array('color' => 'color', 'hover_color' => 'color', 'font_family' => 'font-family', 'font_size' => 'font-size', 'font_weight' => 'font-weight', 'background' => 'background', 'hover_background' => 'background');
        $observe = static function (string $element, string $field, mixed $value, array $row) use (&$values, $families): void {
            $varies = in_array($families[$field], (array) ($row['conditional'] ?? array()), true);
            $key = $varies || null === $value ? '' : (string) $value;
            $values[$element][$field][$key] = ($values[$element][$field][$key] ?? 0) + max(1, (int) ($row['count'] ?? 1));
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
                    // Compare preset identities, not fallback variants, when
                    // deciding whether all instances share an element default.
                    $stack = (string) ($row['font_family'] ?? '');
                    $slug = $system->fontFamilySlug($stack);
                    foreach ($system->fontFamilies as $family) if ($family['slug'] === $slug) $stack = $family['fontFamily'];
                    $observe($element, 'font_family', self::familyKey($stack) . "\n" . $stack, $row);
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
                if (!empty($varied[$field])) continue;
                if ('heading' === $element && 'font_family' === $field) {
                    // Count rendered instances, not text length: a long title
                    // must not outweigh several headings in the site shell.
                    // Missing source families cannot preserve a minority
                    // look against a concrete theme default.
                    if (isset($observed["\n"]) || isset($observed[''])) continue;
                    arsort($observed, SORT_NUMERIC);
                    if (reset($observed) * 2 <= array_sum($observed)) continue;
                    // An inherited minority has no source-owned binding to
                    // protect it from the theme's heading family default.
                    if (array_diff_key($inheritedHeadingFamilies, array((string) array_key_first($observed) => true))) continue;
                } elseif (1 !== count($observed)) continue;
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
     * Choose each authored field independently by strict weighted majority.
     * Each route has equal say; missing observations count against a majority.
     * Ties and isolated controls do not establish a design system.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,string>
     */
    private static function dominantButtonLook(array $usageBySource): array
    {
        $weights = array();
        $total = $count = 0;
        foreach ($usageBySource as $usage) {
            $rows = $usage['button_looks'] ?? array();
            foreach ($rows as $look) {
                ++$count;
                $weight = self::documentShare(1, count($rows));
                $total += $weight;
                foreach ($look as $field => $value) {
                    $weights[$field][$value] = ($weights[$field][$value] ?? 0) + $weight;
                }
            }
        }
        if ($count < 3 || array() === $weights) return array();
        $look = array();
        foreach ($weights as $field => $values) {
            foreach ($values as $value => $weight) {
                if ($weight * 2 > $total) $look[$field] = (string) $value;
            }
        }
        ksort($look, SORT_STRING);
        return $look;
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
        return (string) preg_replace('/\s+/', ' ', CssFontFamily::names($stack)[0] ?? '');
    }

    private static function familyName(string $stack): string
    {
        $names = CssFontFamily::names($stack);
        foreach ($names as $token) {
            if (!in_array(strtolower($token), self::GENERIC_FAMILIES, true) && 1 === preg_match('/^[A-Za-z][A-Za-z0-9 _.\'-]*$/', $token)) return $token;
        }
        return $names[0] ?? '';
    }

    /** Pixel size as rem when the document root is not the default 16px. */
    private static function lengthText(float $px, float $rootPx): string
    {
        if (16.0 === $rootPx || $rootPx <= 0) return CssLength::format($px);
        return rtrim(rtrim(number_format($px / $rootPx, 4, '.', ''), '0'), '.') . 'rem';
    }
}
