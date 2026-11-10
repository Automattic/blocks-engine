<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\ResponsiveCorrespondence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFontShorthand;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/**
 * Binds block colour, font size, font family and padding values that match a
 * design-system token to WordPress presets, so editing the token in Global
 * Styles restyles the block.
 *
 * - Text colour becomes `textColor` (`has-text-color has-{slug}-color`).
 * - Background colour becomes `backgroundColor`
 *   (`has-background has-{slug}-background-color`).
 * - Font size becomes `fontSize` (`has-{slug}-font-size`).
 * - Font family becomes `fontFamily` (`has-{slug}-font-family`).
 * - Padding, margin and block gap become `var:preset|spacing|{slug}`.
 *
 * A value reaches a block in one of two ways, and both are bound:
 *
 * - Literal: the conversion serialized it as a block style attribute with a
 *   matching inline declaration. The inline declaration is removed (or, for
 *   spacing, rewritten to the preset variable) so the saved markup is what
 *   core's save function produces for the new attributes. A `var()` literal is
 *   resolved through custom properties set once, unconditionally, on the
 *   document root, or else through the value the source rendered for the
 *   block's class list.
 * - Class-driven: the block carries the author's classes and an author rule
 *   for those classes sets the value; the block has no attribute for it. The
 *   source documents record, per class list, what the elements carrying it
 *   rendered (`class_styles` in `blocks-engine/design-usage/v1`). When every
 *   element with that class list rendered the same value and it matches a
 *   token, the preset attribute and its class are added. Padding is added as
 *   an inline preset variable, which is what core saves for it.
 *
 * Fonts equal to a projected root or heading default inherit that default,
 * without a block preset class. Matching source font rules yield on those
 * targets, so changing the Global Styles font reaches them.
 *
 * The author class stays on the block, and so does its projected rule: a
 * utility class usually sets more than the bound property (`text-sm` also
 * sets `line-height`). The preset wins without removing it: core's preset
 * classes are `!important`, and a spacing preset is inline. What could still
 * override a preset is another `!important` rule, which is why binding is
 * refused when one can reach the element. The projected native-button rules
 * that restate author classes on `.wp-block-button__link` with `!important`
 * step aside for `.has-text-color` and `.has-background` (see
 * AuthorStylesheetProjector and GeneratedBlockStyleProjector), the same way
 * they step aside for an inline value, so they do not block colour binding.
 * Button typography is not bound: core serializes it on the link, where
 * those rules reset `font` for every value that is not inline.
 *
 * Each block is rewritten on its own, from its delimiter and its own opening
 * tag, so the rewrite gives the same result on a whole page and on any
 * fragment that contains the block. It is idempotent: a bound value is no
 * longer a literal and a block that has a preset attribute is not rebound.
 *
 * A value is left alone when binding could change what renders:
 *
 * - the inline declaration is not exactly the attribute value WordPress
 *   serialized (the markup was not produced from these attributes);
 * - re-encoding the delimiter JSON would not reproduce it byte for byte;
 * - a stylesheet declares the property `!important` for a selector whose
 *   subject this block could match, unless the selector excludes the classes
 *   the bound block carries;
 * - a stylesheet selects on the inline style text (`[style*=…]`) for that
 *   property in a way the rewrite would change;
 * - a `:hover`, `:focus`, `:focus-visible`, `:focus-within` or `:active`
 *   rule sets the property on the block's classes or tags, flat
 *   (`.hover\:bg-x:hover`) or nested (`.hover\:bg-x{&:hover{…}}`). A
 *   button whose literal colour is the `button` element style's token, and
 *   whose state rule the element's `:hover` restates, drops the literal
 *   instead, as does a button painted by a matching bound author class
 *   (rendersThroughElement());
 * - the source renders the property differently under a media condition
 *   (breakpoint or colour scheme), unless every such value matches the same
 *   token;
 * - elements with the block's class list render different values, when
 *   binding a class-driven value or resolving a non-plain literal. A plain
 *   inline colour is block-local evidence, independent of those observations.
 */
final class PresetBinding
{
    /**
     * A type selector at the start of a compound: any CSS identifier, so the
     * hack selectors `_:future` and `_::-webkit-full-page-media` name an
     * element `_` (which no block is) rather than matching every element.
     */
    private const TYPE_SELECTOR = '/^((?:-?[a-z_]|--)[\w-]*)/i';

    /** Blocks whose colour, font-size and spacing support serializes on the wrapper. */
    private const WRAPPER_BLOCKS = array('paragraph', 'heading', 'group', 'columns', 'column', 'list', 'list-item', 'quote', 'buttons');

    /** core/button serializes colour and padding on its inner link. */
    private const LINK_BLOCKS = array('button');

    /** Supports a block type has for values it carries only through classes. */
    private const CLASS_SUPPORTS = array(
        'paragraph' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'heading' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'group' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'list' => array('text', 'background', 'fontSize', 'fontFamily', 'padding'),
        'quote' => array('text', 'background', 'fontSize', 'fontFamily'),
        'columns' => array('text', 'background', 'padding'),
        'column' => array('text', 'background', 'padding'),
        'button' => array('text', 'background', 'padding'),
    );

    /** Properties the source records per class list (see DesignUsageCollector). */
    private const CLASS_STYLE_PROPERTIES = array('color', 'background-color', 'font-size', 'font-family', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left');

    /** Classes WordPress or the engine add to a block; they do not identify the author's class list. */
    private const GENERATED_CLASS = '/^(?:' . CaptureEvidence::RESPONSIVE_COUNTERPART_CLASS_PREFIX . '|' . ResponsiveCorrespondence::TOKEN_CLASS_PREFIX . '|wp-|has-|is-layout-|is-content-justification-|is-nowrap$|is-vertical$|blocks-engine-|align(?:wide|full|left|right|center)$)/';

    private const SPACING_SIDES = array('top', 'right', 'bottom', 'left');

    /** Element-token marker: the element's start-tag attributes are listed as `attr:` tokens. */
    private const KNOWN_ATTRIBUTES = 'attrs:known';
    private const STATE_PSEUDO = '/:(?:hover|focus-visible|focus-within|focus|active)\b/i';

    /**
     * Why a text or background colour stays a literal (see refusals()):
     *
     * - `no_matching_token`: an opaque colour no palette token represents;
     * - `alpha`: a translucent colour with no derived palette preset (its
     *   opaque colour is unmatched, or its alpha cannot be restated by
     *   translucentColor()); observed palette tints use ordinary preset
     *   attributes, keeping color-mix() out of inline styles;
     * - `important`: an `!important` rule could reach the element;
     * - `state_variant`: a `:hover`/`:focus` rule changes the property;
     * - `breakpoint_variant`: a media condition gives it another token or a
     *   value an observation cannot record;
     * - `unsupported_block`: the block type has no colour support for it;
     * - `inline_format_unsupported`: an inline element in rich text that
     *   core's text-colour format cannot carry (a link or other format tag,
     *   extra attributes, or an inline background, which core keeps as a
     *   literal);
     * - `inconsistent_source`: elements with the class list render
     *   different values, the links of a navigation render different
     *   colours (so no one `textColor` describes them), or the markup was
     *   not serialized from the block attributes;
     * - `style_selector`: a stylesheet reads the inline style text;
     * - `unresolved_value`: a `var()` that does not resolve to one colour,
     *   a gradient or another value that is not a plain colour;
     * - `background_padding`: a class-driven background would add
     *   `has-background` to a block whose core style pads that class
     *   (see BACKGROUND_PADDED_BLOCKS), and the source does not set the
     *   padding the core rule would replace.
     */
    public const REFUSAL_REASONS = array('no_matching_token', 'alpha', 'important', 'state_variant', 'breakpoint_variant', 'unsupported_block', 'inline_format_unsupported', 'inconsistent_source', 'style_selector', 'unresolved_value', 'background_padding');

    /**
     * Blocks whose core style pads `.has-background` (`1.25em 2.375em` in
     * block-library: paragraph, heading, list, columns).
     */
    private const BACKGROUND_PADDED_BLOCKS = array('paragraph', 'heading', 'list', 'columns');

    /** Refusal reasons for the `!` observations DesignUsageCollector records. */
    private const OBSERVATION_REASONS = array('!' => 'inconsistent_source', '!important' => 'important', '!alpha' => 'alpha', '!state' => 'state_variant', '!breakpoint' => 'breakpoint_variant', '!value' => 'unresolved_value');

    /** Rich-text blocks whose inline colours bind to core's text-colour format. */
    private const RICH_TEXT_BLOCKS = array('paragraph', 'heading');

    /** Inline tags that can carry a colour in rich text. */
    private const INLINE_COLOR_TAG = '/<(mark|span|a|strong|em|b|i|small|code|s|u|sub|sup)\b([^>]*)>/i';

    /** Inherited text properties a conversion carrier may hand to the one inline element it wraps. */
    private const INHERITED_TEXT_PROPERTIES = array('font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'letter-spacing', 'word-spacing', 'line-height', 'text-transform');

    /** Core's text-colour format background when none is chosen (format-library `transparentValue`). */
    private const INLINE_TRANSPARENT = 'rgba(0, 0, 0, 0)';

    /** @var array<string,list<array{tokens:list<string>,negated:list<string>,attributes:list<array{0:string,1:string|null}>}>> `!important` rules per property family. */
    private array $importantRules = array();
    /** @var array<string,list<array{tokens:list<string>|null,negated:list<string>,fragment:string}>> `[style*=…]` selectors per family; null tokens reach any element. */
    private array $styleTextRules = array();
    /** @var array<string,list<array{tokens:list<string>,negated:list<string>,attributes:list<array{0:string,1:string|null}>,compound?:array<string,mixed>}>> Subjects of interaction-state rules, per family. */
    private array $stateTokens = array();
    /** @var array<string,array<string,string>> Authored class list => property => the one observation every element agreed on, or `!`. */
    private array $classStyles = array();
    /** @var array<string,array<string,list<string>>> Unconditional single-class colour declarations. */
    private array $classColors = array();
    /** @var array<string,list<array<string,mixed>>> Subjects of plain colour rules per family, with their value; null when a condition or layer applies. */
    private array $restingColors = array();
    /** @var array<string,array<string,array<string,list<int>>>> Rule indexes by kind, family and required subject token. */
    private array $subjectBuckets = array();
    /** @var array<string,bool> restsOn() results by property, literal and token sets. */
    private array $restsOnResults = array();
    private int $bound = 0;
    /** Projected font defaults, keyed by the HTML target that inherits them. @var array<string,string> */
    private array $fontDefaults = array();
    /** Whether an author rule selects `mark` elements, which a span made a mark would join. */
    private bool $markRules = false;
    /** Author font rules that may compete with a heading default. @var list<array{selector:string,value:?string}> */
    private array $fontRules = array();
    /** @var array<string,string|false> Root custom properties; false when a value is scoped or conditional. */
    private array $variables = array();
    /** @var array<string,array<string,true>> Values custom properties take outside the root. */
    private array $scopedVariables = array();
    /** @var array<string,string> Custom properties every definition, all outside the root, sets to one value. */
    private array $uniformVariables = array();

    /**
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,array<string,mixed>> $usageBySource Design usage keyed by document source path.
     */
    public function __construct(private readonly DesignSystem $system, array $assets, array $usageBySource = array())
    {
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $conditional = !in_array(strtolower(trim((string) ($asset['media'] ?? ''))), array('', 'all'), true);
            $visitor->visitStyleRules($asset['content'], function (string $prelude, string $body, array $ancestors = array()) use ($conditional): void {
                $this->indexRule($prelude, $body);
                $this->indexVariables($prelude, $body, $ancestors);
                $this->indexRestingColors($prelude, $body, $conditional || array() !== $ancestors);
                $font = self::declared(self::declarations($body), 'font-family');
                $shorthand = self::declared(self::declarations($body), 'font');
                if (null !== $font || null !== $shorthand || str_contains($body, '{')) {
                    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
                        // Unconditional element defaults yield in bindStylesheet().
                        if (array() === $ancestors && preg_match('/^(?:body|h[1-6])$/i', trim($selector))) continue;
                        $this->fontRules[] = array('selector' => $selector, 'value' => null !== $shorthand || str_contains($body, '{') ? null : $font);
                    }
                }
                if (array() === $ancestors) {
                    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                        // Route projections duplicate a plain class for the document
                        // root and its descendants, including the control exclusion.
                        // Remove only that generated scope, not author contexts/states.
                        $routeScope = ':where\(:root:(?:is|not)\(\.be-r-[a-f0-9]+(?:,\.be-r-[a-f0-9]+)*\)\)';
                        $selector = preg_replace('/^' . $routeScope . ' +|' . $routeScope . '$/', '', trim($selector)) ?? $selector;
                        if (1 !== preg_match('/^\.([a-zA-Z_][a-zA-Z0-9_-]*)(?::not\(:where\(\.blocks-engine-role-control\)\))?$/', $selector, $match)) continue;
                        foreach (self::declarations($body) as [$property, $value]) {
                            if (in_array($property, array('color', 'background-color', 'background'), true)) $this->classColors[$match[1]][$property][] = $value;
                        }
                    }
                }
            });
        }
        foreach ($this->scopedVariables as $name => $values) {
            if (!array_key_exists($name, $this->variables) && 1 === count($values)) $this->uniformVariables[$name] = (string) array_key_first($values);
            if (is_string($this->variables[$name] ?? null) && array($this->variables[$name] => true) !== $values) $this->variables[$name] = false;
            if (!array_key_exists($name, $this->variables)) $this->variables[$name] = false;
        }
        $this->classStyles = self::mergeClassStyles($usageBySource);
        $theme = (new ThemeJsonProjection())->project($assets, array_keys($usageBySource), $system)['theme'];
        $families = array_column($theme['settings']['typography']['fontFamilies'] ?? array(), 'fontFamily', 'slug');
        $styles = $theme['styles'] ?? array();
        foreach (array('body', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $tag) {
            $value = 'body' === $tag ? ($styles['typography']['fontFamily'] ?? null)
                : ($styles['elements'][$tag]['typography']['fontFamily'] ?? $styles['elements']['heading']['typography']['fontFamily'] ?? null);
            if (!is_string($value)) continue;
            $this->fontDefaults[$tag] = $families[str_replace('var:preset|font-family|', '', $value)] ?? $value;
        }
    }

    /**
     * Colour values found unbound, by reason, while `$refusals` is
     * collecting; see refusals().
     *
     * @var array<string,int>|null
     */
    private ?array $refusals = null;

    /** Number of values bound to presets so far. */
    public function boundCount(): int
    {
        return $this->bound;
    }

    /**
     * Colour values left as literals in block documents, by reason (every
     * key of REFUSAL_REASONS is present). The documents are bound again,
     * which changes nothing in bound markup since binding is idempotent, so
     * what is counted is exactly what binding refused. Navigation entities
     * are passed as their inner markup, as their links render.
     *
     * @param list<string> $markups
     * @param list<string> $navigationMarkups Inner block markup of each navigation entity.
     * @return array<string,int>
     */
    public function refusals(array $markups, array $navigationMarkups = array()): array
    {
        $bound = $this->bound;
        $this->refusals = array_fill_keys(self::REFUSAL_REASONS, 0);
        foreach ($markups as $markup) $this->bind($markup);
        foreach ($navigationMarkups as $markup) $this->navigationSlug($markup, array());
        $refusals = $this->refusals;
        $this->refusals = null;
        $this->bound = $bound;
        return $refusals;
    }

    public function bind(string $markup): string
    {
        if (!str_contains($markup, '<!-- wp:')) return $markup;
        $markup = $this->bindNavigations($markup);
        $pattern = '/<!-- wp:(' . implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), array_merge(self::WRAPPER_BLOCKS, self::LINK_BLOCKS))) . ') (\{.*?\}) (\/)?-->/s';
        $result = '';
        $offset = 0;
        while (1 === preg_match($pattern, $markup, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            $result .= substr($markup, $offset, $start - $offset);
            $offset = $end;
            if (isset($match[3]) && '/' === $match[3][0]) {
                $result .= $match[0][0];
                continue;
            }
            $rewritten = $this->bindBlock($match[1][0], $match[2][0], $markup, $end);
            if (null === $rewritten) {
                $result .= $match[0][0];
                continue;
            }
            [$delimiter, $tagStart, $tagEnd, $tag] = $rewritten;
            $between = substr($markup, $end, $tagStart - $end);
            if ('button' === $match[1][0] && null !== ($slug = $this->system->buttonVariationSlug()) && str_contains($delimiter, 'is-style-' . $slug)) {
                $between = (string) preg_replace('/\bclass="/', 'class="is-style-' . $slug . ' ', $between, 1);
            }
            $result .= $delimiter . $between . $tag;
            $offset = $tagEnd;
        }
        $result = $this->bindInlineColors($result . substr($markup, $offset));
        if (null !== $this->refusals) $this->refuseUnsupportedBlocks($result);
        return $result;
    }

    /**
     * A navigation whose links all render one token colour through their
     * author classes carries it as `textColor`: core's navigation-link has
     * no colour support, and its links take the navigation's colour. The
     * links keep the engine's per-link colour rules (which win over an
     * inherited colour), so nothing a reader sees changes; the attribute
     * names the colour in the editor and paints what inherits it in the
     * menu. A link that inherits its colour, a submenu or any other inner
     * block leaves the navigation alone, since the attribute would restyle
     * it.
     */
    private function bindNavigations(string $markup): string
    {
        if (!str_contains($markup, '<!-- wp:navigation ')) return $markup;
        return (string) preg_replace_callback('/<!-- wp:navigation (\{(?:(?!-->).)*\}) -->(.*?)<!-- \/wp:navigation -->/s', function (array $match): string {
            $attributes = json_decode($match[1], false);
            if (!$attributes instanceof \stdClass || BlockAttributes::encode($attributes) !== $match[1] || isset($attributes->textColor) || isset($attributes->style->color->text)) return $match[0];
            $classes = is_string($attributes->className ?? null) ? $attributes->className : '';
            $slug = $this->navigationSlug($match[2], $this->tagTokens('<nav>', $classes));
            if (null === $slug) return $match[0];
            $attributes->textColor = $slug;
            ++$this->bound;
            return '<!-- wp:navigation ' . BlockAttributes::encode($attributes) . ' -->' . $match[2] . '<!-- /wp:navigation -->';
        }, $markup);
    }

    /**
     * The token every link of a navigation renders, or null. Links that do
     * not render one are refusals (see refusals()).
     *
     * @param array<string,true> $tokens The navigation element's tokens; empty to only count refusals.
     */
    private function navigationSlug(string $inner, array $tokens): ?string
    {
        if (!preg_match_all('/<!-- wp:([a-z0-9\/-]+)(?: (\{(?:(?!-->).)*\}))? \/?-->/s', $inner, $blocks, PREG_SET_ORDER)) return null;
        $links = $reasons = array();
        $supported = true;
        foreach ($blocks as $block) {
            $name = (string) preg_replace('/^core\//', '', $block[1]);
            $supported = $supported && 'navigation-link' === $name;
            if (!in_array($name, array('navigation-link', 'navigation-submenu'), true)) continue;
            // The author's link classes ride on the item, or on the anchor
            // the item renders (`blocksEngineNavigationAnchor`).
            $attributes = json_decode($block[2] ?? '', true);
            $classes = (is_string($attributes['className'] ?? null) ? $attributes['className'] : '') . ' ' . (is_string($attributes['metadata']['blocksEngineNavigationAnchor']['className'] ?? null) ? $attributes['metadata']['blocksEngineNavigationAnchor']['className'] : '');
            $observation = $this->classStyles[self::authoredClassKey($classes)]['color'] ?? null;
            $slug = $this->presetSlug('color', null, $observation, $reason);
            $links[] = $slug;
            $reasons[] = $reason;
        }
        $slugs = array_unique(array_filter($links, static fn(?string $slug): bool => null !== $slug));
        $bindable = $supported && array() !== $links && !in_array(null, $links, true) && 1 === count($slugs);
        $slug = $bindable ? (string) reset($slugs) : null;
        if (null !== $slug && array() !== $tokens && !$this->safe('color', $tokens, array('has-text-color', 'has-' . $slug . '-color'), false, false, $reason)) {
            $reasons = array_fill(0, count($links), $reason);
            $slug = null;
        }
        if (null !== $slug) return $slug;
        foreach ($links as $index => $linkSlug) {
            $reason = $reasons[$index] ?? null;
            if (null !== $linkSlug && null === $reason) $reason = $supported ? 'inconsistent_source' : 'unsupported_block';
            $this->refuse($reason);
        }
        return null;
    }

    /**
     * Binds inline colours in paragraph and heading rich text to core's
     * text-colour format: a `<mark>` (or a `<span>`, which becomes one)
     * whose colour is a token turns into
     * `<mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-{slug}-color …">`,
     * what the format saves, and its literal colour is removed. The text,
     * the author's classes and other declarations stay. Core keeps an
     * inline background as a literal (it has no preset class for it), so
     * an inline background is kept and counted as a refusal.
     *
     * The format replaces the element that renders the colour; it never
     * wraps it. The conversion carries an author-styled inline element as a
     * marker `<mark>` (`--blocks-engine-richtext-marker`) whose style
     * restates what the element inherits, so an authored inline element
     * that is the whole content of such a carrier would otherwise be bound
     * twice: the carrier to the inherited colour, which renders nowhere,
     * and the element to its own. The carrier's other declarations move
     * onto the element (see collapsedCarrier()) and only the element is
     * bound, to the token it renders. When that colour is not a token,
     * both stay as they are and the element counts as one refusal.
     *
     * The format is skipped when an element has attributes other than `class`,
     * `style` and `role="none"`, when its colour is not an opaque token,
     * when an `!important`, state or style-text rule could reach it, or,
     * for a `<span>`, when an author rule selects `mark` elements. Matching
     * literals on retained elements can still bind in place (bindInlineLiterals()).
     */
    private function bindInlineColors(string $markup): string
    {
        if (!str_contains($markup, ' style="') || !preg_match('/<!-- wp:(?:' . implode('|', self::RICH_TEXT_BLOCKS) . ')\b/', $markup)) return $markup;
        $pattern = '/(<!-- wp:(' . implode('|', self::RICH_TEXT_BLOCKS) . ')(?: \{(?:(?!-->).)*\})? -->)(.*?)(<!-- \/wp:\2 -->)/s';
        return (string) preg_replace_callback($pattern, fn(array $match): string => $match[1] . $this->bindInlineLiterals($this->bindInlineContent($match[3])) . $match[4], $markup);
    }

    /**
     * Inline elements that cannot use core's colour format keep their tag and
     * attributes. Changing only a declaration's value to its preset variable
     * preserves specificity, importance and state overrides. Font families
     * have no rich-text preset format and always bind this way.
     */
    private function bindInlineLiterals(string $html): string
    {
        return (string) preg_replace_callback(self::INLINE_COLOR_TAG, function (array $tag): string {
            $values = self::tagAttributes($tag[2]);
            if (null === $values || !isset($values['style'])) return $tag[0];
            $declarations = self::declarations($values['style']);
            $tokens = $this->tagTokens('<' . strtolower($tag[1]) . '>', $values['class'] ?? '');
            $changed = false;
            foreach ($declarations as &$declaration) {
                [$property, $value] = $declaration;
                if (!in_array($property, array('color', 'font-family'), true) || str_contains($value, 'var(')) continue;
                $slug = $this->presetSlug($property, $value, null);
                if (null === $slug || !$this->safe($property, $tokens, array(), true, false, $reason, true)) continue;
                $declaration[1] = 'var(--wp--preset--' . $property . '--' . $slug . ')';
                $changed = true;
                ++$this->bound;
            }
            unset($declaration);
            return $changed ? self::withAttribute($tag[0], 'style', self::declarationText($declarations)) : $tag[0];
        }, $html);
    }

    private function bindInlineContent(string $html): string
    {
        if (!preg_match_all(self::INLINE_COLOR_TAG, $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $html;
        $edits = array();
        $skipUntil = 0;
        foreach ($tags as $tag) {
            if ($tag[0][1] < $skipUntil) continue;
            $carried = $this->collapsedCarrier($html, $tag[0][1], strlen($tag[0][0]));
            if (null !== $carried) {
                [$end, $name, $values, $inner, $contentStart] = $carried;
                $skipUntil = $end;
                if (null !== self::paintedBackground(self::declarations((string) ($values['style'] ?? '')))) $this->refuse('inline_format_unsupported');
                $bound = $this->inlineFormat($name, $values, $html, $contentStart, $reason);
                if (null === $bound) {
                    $this->refuse($reason);
                    continue;
                }
                $edits[$tag[0][1]] = array($end - $tag[0][1], $bound[0] . $inner . '</mark>');
                continue;
            }
            $name = strtolower($tag[1][0]);
            $attributes = self::tagAttributes($tag[2][0]);
            $values = $attributes ?? array();
            $declarations = self::declarations((string) ($values['style'] ?? ''));
            $color = self::declared($declarations, 'color');
            $background = self::paintedBackground($declarations);
            if (!self::paints($color)) {
                if (null !== $background) $this->refuse('inline_format_unsupported');
                continue;
            }
            $classes = preg_split('/\s+/', trim((string) ($values['class'] ?? ''))) ?: array();
            if (in_array('has-inline-color', $classes, true)) continue;
            $close = null !== $attributes && 'span' === $name ? self::closingTag($html, 'span', $tag[0][1] + strlen($tag[0][0])) : null;
            $bound = null === $attributes ? null : $this->inlineFormat($name, $values, $html, $tag[0][1] + strlen($tag[0][0]), $reason);
            if (null !== $background) $this->refuse('inline_format_unsupported');
            if (null === $bound) {
                $replacement = $this->bindInlineLiterals($tag[0][0]);
                if (!str_contains($replacement, 'color:var(--wp--preset--color--')) $this->refuse($reason ?? 'inline_format_unsupported');
                if ($tag[0][0] !== $replacement) $edits[$tag[0][1]] = array(strlen($tag[0][0]), $replacement);
                continue;
            }
            $edits[$tag[0][1]] = array(strlen($tag[0][0]), $bound[0]);
            if (null !== $close) $edits[$close[0]] = array($close[1], '</mark>');
        }
        krsort($edits);
        foreach ($edits as $offset => [$length, $replacement]) $html = substr_replace($html, $replacement, $offset, $length);
        return $html;
    }

    /**
     * The text-colour format opening tag for an inline element whose colour
     * is a token, or null with the reason it is not bound. `$html` and
     * `$after` locate the element's content, for a `<span>`'s closing tag.
     *
     * @param array<string,string> $values The element's attributes.
     * @return array{0:string}|null
     */
    private function inlineFormat(string $name, array $values, string $html, int $after, ?string &$reason = null): ?array
    {
        $reason = 'inline_format_unsupported';
        $declarations = self::declarations((string) ($values['style'] ?? ''));
        $color = self::declared($declarations, 'color');
        $background = self::paintedBackground($declarations);
        $classes = preg_split('/\s+/', trim((string) ($values['class'] ?? ''))) ?: array();
        $carrier = in_array($name, array('mark', 'span'), true)
            && array() === array_diff(array_keys($values), array('class', 'style', 'role'))
            && 'none' === ($values['role'] ?? 'none')
            && !self::paints(self::declared($declarations, 'background'));
        if (!$carrier || null === $color || ('span' === $name && (null === self::closingTag($html, 'span', $after) || $this->markRules))) return null;
        $slug = $this->presetSlug('color', $color, null, $reason);
        $tokens = $this->tagTokens('<mark>', implode(' ', $classes)) + $this->tagTokens('<' . $name . '>', '');
        $added = null === $slug ? array() : array('has-inline-color', 'has-' . $slug . '-color');
        if (null === $slug || !$this->safe('color', $tokens, $added, false, false, $reason)) return null;
        $kept = array_values(array_filter($declarations, static fn(array $declaration): bool => !in_array($declaration[0], array('color', 'background-color'), true)));
        $style = 'background-color:' . ($background ?? self::INLINE_TRANSPARENT) . (array() === $kept ? '' : ';' . self::declarationText($kept));
        $class = implode(' ', array_values(array_unique(array_filter(array_merge($added, $classes), static fn(string $class): bool => '' !== $class))));
        ++$this->bound;
        $reason = null;
        return array('<mark style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' . (isset($values['role']) ? ' role="none"' : '') . '>');
    }

    /**
     * A conversion carrier (a `<mark>` whose style holds the
     * `--blocks-engine-richtext-marker` of an author-styled inline element)
     * whose whole content is one authored `<mark>` or `<span>` that sets its
     * own inline colour. The carrier's colour renders nowhere, so the two
     * are one element: the authored element, with the carrier's other
     * declarations (its marker and inherited text properties) and its role
     * and subject classes added. A carrier that paints a background, has
     * other classes or declares a property that is not inherited text
     * styling, or an element that has its own marker or redeclares one of
     * the carrier's properties, is not collapsed.
     *
     * @return array{0:int,1:string,2:array<string,string>,3:string,4:int}|null End offset of the carrier, the element's tag name and merged attributes, its content and where that starts.
     */
    private function collapsedCarrier(string $html, int $offset, int $length): ?array
    {
        if (1 !== preg_match('/\G<mark\b([^>]*)>/i', $html, $outer, 0, $offset)) return null;
        $outerValues = self::tagAttributes($outer[1]);
        if (null === $outerValues || array() !== array_diff(array_keys($outerValues), array('style', 'class')) || !str_contains((string) ($outerValues['style'] ?? ''), '--blocks-engine-richtext-marker:')) return null;
        // The carrier's role and subject classes go with its marker.
        $carrierClasses = array_values(array_filter(preg_split('/\s+/', trim((string) ($outerValues['class'] ?? ''))) ?: array(), static fn(string $class): bool => '' !== $class));
        foreach ($carrierClasses as $class) {
            if ('blocks-engine-role-richtext' !== $class && 'blocks-engine-subject' !== $class && !str_starts_with($class, 'blocks-engine-subject-')) return null;
        }
        if (1 !== preg_match('/\G<(mark|span)\b([^>]*)>/i', $html, $inner, 0, $offset + $length)) return null;
        $name = strtolower($inner[1]);
        $values = self::tagAttributes($inner[2]);
        $contentStart = $offset + $length + strlen($inner[0]);
        $innerClose = self::closingTag($html, $name, $contentStart);
        $outerClose = self::closingTag($html, 'mark', $offset + $length);
        if (null === $values || null === $innerClose || null === $outerClose || $innerClose[0] + $innerClose[1] !== $outerClose[0]) return null;
        $declarations = self::declarations((string) ($values['style'] ?? ''));
        if (!self::paints(self::declared($declarations, 'color')) || str_contains((string) ($values['style'] ?? ''), '--blocks-engine-richtext-marker:')) return null;
        $own = array_flip(array_column($declarations, 0));
        $moved = array();
        foreach (self::declarations($outerValues['style']) as [$property, $value]) {
            if ('color' === $property || (in_array($property, array('background', 'background-color'), true) && !self::paints($value))) continue;
            if (isset($own[$property]) || (!str_starts_with($property, '--') && !in_array($property, self::INHERITED_TEXT_PROPERTIES, true))) return null;
            $moved[] = array($property, $value);
        }
        $values['style'] = self::declarationText(array_merge($declarations, $moved));
        if (array() !== $carrierClasses) $values['class'] = trim((string) ($values['class'] ?? '') . ' ' . implode(' ', $carrierClasses));
        return array($outerClose[0] + $outerClose[1], $name, $values, substr($html, $contentStart, $innerClose[0] - $contentStart), $contentStart);
    }

    /** @param list<array{0:string,1:string}> $declarations An inline background colour that paints, or null. */
    private static function paintedBackground(array $declarations): ?string
    {
        $background = self::declared($declarations, 'background-color') ?? self::declared($declarations, 'background');
        return self::paints($background) ? $background : null;
    }

    /**
     * Literal colours on blocks binding does not handle: a block type
     * without colour support for them, or a self-closing block.
     */
    private function refuseUnsupportedBlocks(string $markup): void
    {
        if (!preg_match_all('/<!-- wp:([a-z0-9\/-]+) (\{(?:(?!-->).)*\}) (\/)?-->/s', $markup, $blocks, PREG_SET_ORDER)) return;
        foreach ($blocks as $block) {
            $name = (string) preg_replace('/^core\//', '', $block[1]);
            if ('/' !== ($block[3] ?? '') && in_array($name, array_merge(self::WRAPPER_BLOCKS, self::LINK_BLOCKS), true)) continue;
            $attributes = json_decode($block[2], true);
            foreach (array('text', 'background') as $key) {
                $literal = is_array($attributes) ? ($attributes['style']['color'][$key] ?? null) : null;
                if (is_string($literal) && !str_starts_with($literal, 'var:') && self::paints($literal)) $this->refuse('unsupported_block');
            }
        }
    }

    /** Counts the literal colours of a block binding cannot rewrite. */
    private function refuseLiteralColors(mixed $attributes): void
    {
        foreach (array('text', 'background') as $key) {
            $literal = $attributes instanceof \stdClass ? ($attributes->style->color->{$key} ?? null) : null;
            if (is_string($literal) && !str_starts_with($literal, 'var:') && self::paints($literal)) $this->refuse('inconsistent_source');
        }
    }

    private function refuse(?string $reason): void
    {
        if (null !== $this->refusals && null !== $reason) $this->refusals[$reason] = ($this->refusals[$reason] ?? 0) + 1;
    }

    /** Whether a colour value or observation paints a colour of its own. */
    private static function paints(?string $value): bool
    {
        return null !== $value && '' !== $value && '!transparent' !== $value && null !== self::unboundReason('color', $value);
    }

    /** @param list<array{0:string,1:string}> $declarations The last value declared for a property, or null. */
    private static function declared(array $declarations, string $property): ?string
    {
        $value = null;
        foreach ($declarations as [$name, $declared]) if ($name === $property) $value = $declared;
        return $value;
    }

    /**
     * A tag's attributes by lower-case name, decoded; null when the
     * attribute text is not plain double-quoted `name="value"` pairs.
     *
     * @return array<string,string>|null
     */
    private static function tagAttributes(string $text): ?array
    {
        $text = rtrim($text, " \t\n\r/");
        if (false === preg_match_all('/\s+([a-zA-Z_:][-a-zA-Z0-9_:.]*)="([^"]*)"/', $text, $matches, PREG_SET_ORDER)) return null;
        if (implode('', array_column($matches, 0)) !== $text) return null;
        $attributes = array();
        foreach ($matches as $match) $attributes[strtolower($match[1])] = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $attributes;
    }

    /** @return array{0:int,1:int}|null Offset and length of the tag that closes an element opened before `$from`. */
    private static function closingTag(string $html, string $name, int $from): ?array
    {
        $depth = 1;
        if (!preg_match_all('/<(\/?)' . $name . '\b[^>]*>/i', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $from)) return null;
        foreach ($matches as $match) {
            $depth += '/' === $match[1][0] ? -1 : 1;
            if (0 === $depth) return array($match[0][1], strlen($match[0][0]));
        }
        return null;
    }

    /**
     * @return array{0:string,1:int,2:int,3:string}|null New delimiter, tag offsets in the source markup, and the new tag.
     */
    private function bindBlock(string $name, string $json, string $markup, int $after): ?array
    {
        $attributes = json_decode($json, false);
        if (!$attributes instanceof \stdClass || BlockAttributes::encode($attributes) !== $json) {
            $this->refuseLiteralColors($attributes);
            return null;
        }
        $observed = $this->classStyles[self::authoredClassKey(is_string($attributes->className ?? null) ? $attributes->className : '')] ?? array();
        if (!isset($attributes->style) && array() === $observed) return null;
        if (isset($attributes->style) && !$attributes->style instanceof \stdClass) return null;

        $tag = $this->styledTag($name, $markup, $after);
        if (null === $tag) {
            $this->refuseLiteralColors($attributes);
            return null;
        }
        [$tagStart, $tagEnd, $html] = $tag;
        $classes = (string) self::attribute($html, 'class');
        $declarations = self::declarations((string) self::attribute($html, 'style'));
        $tokens = $this->tagTokens($html, $classes . ' ' . (is_string($attributes->className ?? null) ? $attributes->className : ''), true);
        $supports = self::CLASS_SUPPORTS[$name] ?? array();
        $isLink = in_array($name, self::LINK_BLOCKS, true);
        $addClasses = $dropClasses = array();
        $bound = 0;
        $style = $attributes->style ?? new \stdClass();
        $deviceOverridden = $this->deviceOverriddenProperties($attributes, $declarations);

        $variation = $isLink && null !== $this->system->buttonVariationSlug() && $this->matchesButtonVariation($style);
        $buttonLook = $isLink && (isset($this->system->elements['button']) || $variation);
        if ($variation) {
            $class = 'is-style-' . $this->system->buttonVariationSlug();
            $attributes->className = trim(($attributes->className ?? '') . ' ' . $class);
            ++$bound;
        }
        if ($buttonLook) {
            foreach (array('fontFamily' => 'font-family', 'fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'textTransform' => 'text-transform', 'letterSpacing' => 'letter-spacing') as $key => $property) {
                $value = $style->typography->{$key} ?? null;
                if (is_string($value) && $this->matchesButtonElement($property, $value) && self::removeDeclaration($declarations, $property, $value)) {
                    unset($style->typography->{$key});
                    if ('fontSize' === $key) $dropClasses[] = 'has-custom-font-size';
                    ++$bound;
                }
            }
            $value = $style->border->radius ?? null;
            if (is_string($value) && $this->matchesButtonElement('border-radius', $value) && self::removeDeclaration($declarations, 'border-radius', $value)) {
                unset($style->border->radius);
                ++$bound;
            }
            foreach (array('top', 'right', 'bottom', 'left') as $side) {
                $value = $style->spacing->padding->{$side} ?? null;
                if (is_string($value) && $this->matchesButtonElement('padding-' . $side, $value) && self::removeDeclaration($declarations, 'padding-' . $side, str_starts_with($value, 'var:preset|spacing|') ? 'var(--wp--preset--spacing--' . substr($value, strlen('var:preset|spacing|')) . ')' : $value)) {
                    unset($style->spacing->padding->{$side});
                    ++$bound;
                }
            }
            foreach (array('text' => 'color', 'background' => 'background-color') as $key => $property) {
                $value = $style->color->{$key} ?? null;
                if (!is_string($value) || !$this->matchesButtonElement($property, $value)) continue;
                $slug = $this->presetSlug('color', $value, null);
                $added = null === $slug ? array() : array('text' === $key ? 'has-text-color' : 'has-background', 'has-' . $slug . ('text' === $key ? '-color' : '-background-color'));
                // Native button elements need explicit palette bindings; link
                // variants retain their existing element-default ownership.
                if ('button' === ($attributes->tagName ?? null) && null !== $slug && $this->safe($property, $tokens, $added)) continue;
                if (self::removeDeclaration($declarations, $property, $value)) {
                    unset($style->color->{$key});
                    $dropClasses[] = 'text' === $key ? 'has-text-color' : 'has-background';
                    ++$bound;
                }
            }
        }

        $presets = array(
            'text' => array('textColor', 'color', 'color', 'has-text-color', 'has-%s-color'),
            'background' => array('backgroundColor', 'background-color', 'background-color', 'has-background', 'has-%s-background-color'),
        );
        foreach ($presets as $key => [$attribute, $property, $observedProperty, $flagClass, $presetClass]) {
            if (isset($attributes->{$attribute})) continue;
            // A core preset class is !important and would mask the value the
            // device-inline runtime writes for another device.
            if (isset($deviceOverridden[$property]) || ('background' === $key && isset($deviceOverridden['background']))) continue;
            $literal = $style->color->{$key} ?? null;
            if ($buttonLook && null === $literal && $this->matchesButtonElement($property, (string) ($observed[$observedProperty] ?? ''))) continue;
            if (is_string($literal) && str_starts_with($literal, 'var:')) continue;
            if (!is_string($literal) && !in_array($key, $supports, true)) continue;
            $added = array($flagClass, sprintf($presetClass, '%s'));
            $observation = $observed[$observedProperty] ?? null;
            if (is_string($literal) && null !== CssColor::parse($literal)) {
                // A plain inline colour belongs to this block, not every
                // element sharing its author classes. Ordinary breakpoint
                // rules cannot override it. safe() still checks all reachable
                // !important, state and style-text rules; removeDeclaration()
                // below proves that the literal really is serialized inline.
                $observation = null;
            }
            $slug = $this->presetSlug('color', $literal, $observation, $reason);
            $safe = null !== $slug && $this->safe($property, $tokens, array_map(static fn(string $class): string => sprintf($class, $slug), $added), false, false, $reason);
            if (!$safe && is_string($literal) && ($this->rendersThroughClass($name, $property, $literal, $tokens) || ('state_variant' === $reason && $this->rendersThroughElement($name, $key, $slug ?? $this->slug('color', $this->resolve($literal)) ?? '', $property, $tokens)))) {
                // A preset class would mask the hover colour; the button
                // element or author class renders this value with its state, so the
                // literal only duplicated it.
                if (!self::removeDeclaration($declarations, $property, $literal)) {
                    $this->refuse('inconsistent_source');
                    continue;
                }
                unset($style->color->{$key});
                $dropClasses[] = $flagClass;
                ++$bound;
                continue;
            }
            if (!$safe && 'state_variant' === $reason && null !== $slug && is_string($literal) && $this->restsOn($property, $literal, $tokens, 'button' === $name ? array($this->tagTokens($html, $classes, true), $this->tagTokens('<div>', 'wp-block-button ' . (is_string($attributes->className ?? null) ? $attributes->className : ''))) : null) && $this->inlinePreset($style->color, $key, 'color', $slug, $declarations, $property, $literal)) {
                // A preset class is !important and would mask the state
                // rule. The resting rules paint this exact colour, so the
                // inline declaration keeps its place in the cascade and only
                // reads its value from the token.
                ++$bound;
                continue;
            }
            if (!$safe) {
                $this->refuse($reason);
                continue;
            }
            if ('background' === $key && !is_string($literal) && !$this->keepsPaddingWithBackground($name, $classes, $declarations, $observed)) {
                $this->refuse('background_padding');
                continue;
            }
            if (is_string($literal)) {
                if (!self::removeDeclaration($declarations, $property, $literal)) {
                    $this->refuse('inconsistent_source');
                    continue;
                }
                unset($style->color->{$key});
            }
            $attributes->{$attribute} = $slug;
            array_push($addClasses, ...array_map(static fn(string $class): string => sprintf($class, $slug), $added));
            ++$bound;
        }

        // core/button's border preset is serialized on the same inner element
        // as its text and background, including tagName: button.
        $border = $style->border->color ?? null;
        if ($isLink && !isset($attributes->borderColor) && is_string($border) && !str_starts_with($border, 'var:')) {
            $slug = $this->presetSlug('color', $border, null, $reason);
            $added = null === $slug ? array() : array('has-border-color', 'has-' . $slug . '-border-color');
            if (null !== $slug && $this->safe('border-color', $tokens, $added, false, false, $reason)) {
                if (self::removeDeclaration($declarations, 'border-color', $border)) {
                    unset($style->border->color);
                    $attributes->borderColor = $slug;
                    array_push($addClasses, ...$added);
                    ++$bound;
                } else $this->refuse('inconsistent_source');
            } else $this->refuse($reason);
        }

        $typography = array(
            'fontSize' => array('fontSize', 'font-size', 'has-%s-font-size'),
            'fontFamily' => array('fontFamily', 'font-family', 'has-%s-font-family'),
        );
        foreach ($typography as $key => [$attribute, $property, $presetClass]) {
            if ($isLink) {
                // Button links carry no font preset class; a family equal to
                // a preset stack reads its variable from the same inline
                // declaration. Sizes stay as authored beside the element size.
                if ('fontFamily' !== $key || isset($attributes->{$attribute})) continue;
                $literal = $style->typography->{$key} ?? null;
                $slug = is_string($literal) && !str_starts_with($literal, 'var:') ? $this->presetSlug($property, $literal, null) : null;
                if (null !== $slug && $this->inlinePreset($style->typography, $key, $property, $slug, $declarations, $property, $literal)) ++$bound;
                continue;
            }
            if (isset($attributes->{$attribute})) continue;
            // The responsive typography carrier owns the base and media
            // declarations. A core size class is !important and would freeze
            // it at one step; bind its stylesheet declarations instead.
            if ('fontSize' === $key && str_contains($classes, 'blocks-engine-responsive-typography-')) continue;
            if (isset($deviceOverridden[$property])) continue;
            $literal = $style->typography->{$key} ?? null;
            if (is_string($literal) && str_starts_with($literal, 'var:')) continue;
            if (!is_string($literal) && !in_array($key, $supports, true)) continue;
            $slug = $this->presetSlug($property, $literal, $observed[$property] ?? null);
            if (null === $slug || !$this->safe($property, $tokens, array(sprintf($presetClass, $slug)))) continue;
            if (is_string($literal)) {
                if (!self::removeDeclaration($declarations, $property, $literal)) continue;
                unset($style->typography->{$key});
            }
            // Element defaults own matching fonts; a preset class would pin
            // the block to the imported font after a Global Styles change.
            if ('fontFamily' !== $key || !$this->inheritsFont($html, $this->resolve($literal ?? $observed[$property] ?? ''))) {
                $attributes->{$attribute} = $slug;
                $addClasses[] = sprintf($presetClass, $slug);
            }
            ++$bound;
        }

        foreach (array('padding', 'margin') as $box) {
            if ($isLink && 'margin' === $box) continue;
            $sides = $style->spacing->{$box} ?? null;
            if ($sides instanceof \stdClass) {
                foreach (self::SPACING_SIDES as $side) {
                    $value = $sides->{$side} ?? null;
                    if (!is_string($value) || str_starts_with($value, 'var:')) continue;
                    $property = $box . '-' . $side;
                    $slug = $this->presetSlug('spacing', $value, 'padding' === $box ? ($observed[$property] ?? null) : null);
                    if (null === $slug || !$this->safe($property, $tokens, array(), true) || !self::replaceDeclaration($declarations, $property, $value, 'var(--wp--preset--spacing--' . $slug . ')')) continue;
                    $sides->{$side} = 'var:preset|spacing|' . $slug;
                    ++$bound;
                }
            } elseif ('padding' === $box && in_array('padding', $supports, true) && !isset($style->spacing->padding)) {
                // Class-driven padding is added side by side, in core's
                // serialization order, as inline preset variables.
                $padding = new \stdClass();
                foreach (self::SPACING_SIDES as $side) {
                    $property = 'padding-' . $side;
                    $slug = $this->presetSlug('spacing', null, $observed[$property] ?? null);
                    if (null === $slug || array() !== array_filter($declarations, static fn(array $declaration): bool => str_starts_with($declaration[0], 'padding')) || !$this->safe($property, $tokens, array(), true, true)) continue;
                    $padding->{$side} = 'var:preset|spacing|' . $slug;
                }
                if (array() !== get_object_vars($padding)) {
                    if (!isset($style->spacing) || !$style->spacing instanceof \stdClass) $style->spacing = new \stdClass();
                    $style->spacing->padding = $padding;
                    foreach (get_object_vars($padding) as $side => $value) {
                        $declarations[] = array('padding-' . $side, 'var(--wp--preset--spacing--' . substr((string) $value, strlen('var:preset|spacing|')) . ')');
                        ++$bound;
                    }
                }
            }
        }
        $gap = $style->spacing->blockGap ?? null;
        if (!$isLink && is_string($gap) && !str_starts_with($gap, 'var:')) {
            $slug = $this->presetSlug('spacing', $gap, null);
            if (null !== $slug && $this->safe('gap', $tokens, array(), true)) {
                $style->spacing->blockGap = 'var:preset|spacing|' . $slug;
                ++$bound;
            }
        }

        if (0 === $bound) return null;
        $this->bound += $bound;
        if (array() !== get_object_vars($style)) $attributes->style = $style;
        if (isset($attributes->style)) self::prune($attributes);
        $newStyle = self::declarationText($declarations);
        $newHtml = self::withAttribute($html, 'style', '' === $newStyle ? null : $newStyle);
        if (array() !== $addClasses || array() !== $dropClasses) {
            $current = array_diff(preg_split('/\s+/', trim($classes)) ?: array(), $dropClasses);
            $newHtml = self::withAttribute($newHtml, 'class', implode(' ', array_values(array_unique(array_filter(array_merge($current, $addClasses), static fn(string $class): bool => '' !== $class)))));
        }
        $delimiter = '<!-- wp:' . $name . (array() === get_object_vars($attributes) ? '' : ' ' . BlockAttributes::encode($attributes)) . ' -->';
        return array($delimiter, $tagStart, $tagEnd, $newHtml);
    }

    /**
     * Binds the values a stylesheet sets that equal a design-system token to
     * the token's preset variable, keeping the literal as the fallback:
     * `color:#93a8ce` becomes
     * `color:var(--wp--preset--color--accent-1,#93a8ce)`.
     *
     * This reaches what block attributes cannot: links inside text,
     * navigation items, interaction states, and author classes whose value
     * the block does not carry. The declaration keeps its selector, its
     * place in the cascade and its `!important`, so the only thing that
     * changes is where the value comes from: it renders the token's value,
     * which is the literal itself, until a site owner edits the token in
     * Global Styles.
     *
     * - A colour (`color`, `background-color`, a lone-colour `background`,
     *   or a custom property whose whole value is a colour) binds when it is
     *   a token's exact colour. Compound custom properties and backgrounds
     *   also bind literal colour tokens, including gradient stops.
     *   A `var()` in a whole colour must resolve the same way as
     *   for a block literal.
     * - A font size binds only when it is the preset's own length, and a
     *   font family only when it is the preset's own stack, so no fallback
     *   or rounding changes what renders. Font-shorthand custom properties
     *   bind only an exactly matching complete stack, in place, leaving the
     *   rest of the shorthand literal.
     */
    public function bindStylesheet(string $css): string
    {
        $transformer = new CssStylesheetTransformer();
        // Split selector arms so a shared utility still owns its font on
        // non-heading targets, including class arms that AuthorStylesheetProjector
        // scoped away from engine roles. Keep that exclusion; :where preserves
        // the original specificity.
        $css = $transformer->transform($css, function (string $prelude, string $body, array $ancestors): string|array {
            foreach ($ancestors as $ancestor) {
                if (!preg_match('/^@layer(?:\s|$)/', $ancestor)) return $prelude;
            }
            $value = self::declared(self::declarations($body), 'font-family');
            if (null === $value || str_contains($body, '{')) return $prelude;
            $value = $this->resolve($value);
            $rules = array();
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
                $selector = trim($selector);
                $tag = strtolower($selector);
                if (1 === preg_match('/^(?::root\s+)?body\.editor-styles-wrapper$/', $tag)) $tag = 'body';
                $kept = $body;
                if (isset($this->fontDefaults[$tag])) {
                    $kept = self::declarationText(array_values(array_filter(self::declarations($body), static fn(array $pair): bool => 'font-family' !== $pair[0])));
                } elseif (preg_match('/^\.[a-zA-Z_][a-zA-Z0-9_-]*(?::not\(:where\(\.blocks-engine-role-(?:' . implode('|', EngineMarker::ROLE_KINDS) . ')(?:\s*,\s*\.blocks-engine-role-(?:' . implode('|', EngineMarker::ROLE_KINDS) . '))*\)\))?$/', trim($selector))) {
                    $excluded = array();
                    foreach ($this->fontDefaults as $target => $font) {
                        if ('body' !== $target && $this->sameFont($value, $font) && !$this->hasCompetingFont($target, $value)) $excluded[] = $target;
                    }
                    if (array() !== $excluded) {
                        $other = self::declarationText(array_values(array_filter(self::declarations($body), static fn(array $pair): bool => 'font-family' !== $pair[0])));
                        if ('' !== $other) $rules[] = array('prelude' => $selector, 'body' => $other);
                        $kept = 'font-family:' . self::declared(self::declarations($body), 'font-family');
                        $selector .= ':not(:where(' . implode(',', $excluded) . '))';
                    }
                }
                $rules[] = array('prelude' => $selector, 'body' => $kept);
            }
            return $rules;
        });
        return $transformer->transformStyleRules($css, function (string $prelude, string $body) use ($transformer): string {
            // Only projected button-link rules yield to the element. Variants
            // keep their selectors and the specificity fix from #3188.
            if (isset($this->system->elements['button']['padding']) && str_contains($prelude, '.wp-block-button__link')
                && array() === array_filter(CssValueSplitter::splitTopLevel($prelude, array(',')), static fn(string $arm): bool => !str_contains($arm, '.wp-block-button__link'))
                && !preg_match('/:(?:hover|focus|active|disabled)|!important|\{/i', $prelude . $body)) {
                [$shared, $kept] = $this->partitionButtonDeclarations($body);
                $body = implode(';', $kept);
            }
            if (null !== ($slug = $this->system->buttonVariationSlug()) && str_contains($prelude, '.wp-block-button__link') && !preg_match('/:(?:hover|focus|active|disabled)|!important|\{/i', $prelude . $body)
                && array() === array_filter(CssValueSplitter::splitTopLevel($prelude, array(',')), static fn(string $arm): bool => !str_contains($arm, '.wp-block-button__link'))) {
                [$shared, $kept] = $this->partitionButtonDeclarations($body);
                if (array() !== $shared) {
                    // Keep off-look controls unchanged, but let the registered
                    // variation beat both author rules and wp-element-button.
                    $excluded = implode(',', array_map(static fn(string $arm): string => trim($arm) . ':not(:where(.is-style-' . $slug . ' *))', CssValueSplitter::splitTopLevel($prelude, array(','))));
                    return $prelude . '{' . $this->bindRuleBody($transformer, implode(';', $kept), false) . '}' . $excluded . '{' . $this->bindRuleBody($transformer, implode(';', $shared), false) . '}';
                }
            }
            return $prelude . '{' . $this->bindRuleBody($transformer, $body, self::rootSelector($prelude)) . '}';
        });
    }

    /**
     * Yield only independent cascade families. A retained shorthand can reset
     * a matching longhand, so those declarations must travel together. Keeping
     * the original ordered family preserves its resolved per-longhand values,
     * including resets and values we cannot safely expand (variables, logical
     * properties and future shorthand syntax).
     *
     * @return array{list<string>, list<string>}
     */
    private function partitionButtonDeclarations(string $body): array
    {
        $declarations = CssValueSplitter::splitTopLevel($body, array(';'));
        $families = $matches = $retained = array();
        foreach ($declarations as $index => $declaration) {
            $pair = explode(':', $declaration, 2);
            $property = strtolower(trim($pair[0]));
            $family = str_starts_with($property, '--') ? $property : explode('-', $property)[0];
            $family = match ($family) {
                'top', 'right', 'bottom', 'left' => 'inset',
                'line' => 'font',
                default => $family,
            };
            $families[$index] = $family;
            $matches[$index] = 2 === count($pair) && $this->matchesButtonElement($property, trim($pair[1]));
            if (!$matches[$index]) $retained[$family] = true;
        }
        $shared = $kept = array();
        foreach ($declarations as $index => $declaration) {
            if ($matches[$index] && !isset($retained[$families[$index]]) && !isset($retained['all'])) $shared[] = $declaration;
            else $kept[] = $declaration;
        }
        return array($shared, $kept);
    }

    /** Only complete, exactly matching blocks opt into a variation. */
    private function matchesButtonVariation(\stdClass $style): bool
    {
        foreach (array('fontFamily' => 'font-family', 'fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'textTransform' => 'text-transform', 'letterSpacing' => 'letter-spacing') as $key => $property) {
            if (in_array($key, array('textTransform', 'letterSpacing'), true) && !isset($this->system->buttonVariation['textTransform' === $key ? 'text_transform' : 'letter_spacing'])) continue;
            if (!$this->matchesButtonElement($property, (string) ($style->typography->{$key} ?? ''))) return false;
        }
        if (!$this->matchesButtonElement('border-radius', (string) ($style->border->radius ?? ''))) return false;
        foreach (self::SPACING_SIDES as $side) if (!$this->matchesButtonElement('padding-' . $side, (string) ($style->spacing->padding->{$side} ?? ''))) return false;
        foreach (array('text' => 'color', 'background' => 'background-color') as $key => $property) if (!$this->matchesButtonElement($property, (string) ($style->color->{$key} ?? ''))) return false;
        return true;
    }

    /** Compare complete stacks, not just the primary family. */
    private function sameFont(string $a, string $b): bool
    {
        return '' !== DesignSystem::familyKey($a) && DesignSystem::familyKey($a) === DesignSystem::familyKey($b)
            && strtolower(str_replace(array(' ', '"', "'"), '', $a)) === strtolower(str_replace(array(' ', '"', "'"), '', $b));
    }

    /**
     * Dropping an explicit class font is safe only when no different author
     * font can replace it. Class overlap is not known here, so ambiguous,
     * conditional and shorthand rules conservatively retain the class rule.
     */
    private function hasCompetingFont(string $tag, string $value): bool
    {
        foreach ($this->fontRules as $rule) {
            $subject = self::subjectCompound($rule['selector']);
            if (preg_match(self::TYPE_SELECTOR, $subject, $match) && strtolower($match[1]) !== $tag) continue;
            if (null === self::subjectTokens($rule['selector'])) continue;
            if (null !== $rule['value'] && $this->sameFont($value, $this->resolve($rule['value']))) continue;
            return true;
        }
        return false;
    }

    private function inheritsFont(string $html, string $value): bool
    {
        if (!preg_match('/^<(h[1-6]|p|div|ul|ol|li|blockquote)\b/i', $html, $match)) return false;
        $tag = strtolower($match[1]);
        $default = $this->fontDefaults[$tag] ?? (str_starts_with($tag, 'h') ? null : ($this->fontDefaults['body'] ?? null));
        return null !== $default && $this->sameFont($value, $default);
    }

    /** Equality only: yielding to Global Styles must never change paint. */
    private function matchesButtonElement(string $property, string $value): bool
    {
        $fields = array('color' => 'color', 'background' => 'background', 'background-color' => 'background', 'font-family' => 'font_family', 'font-size' => 'font_size', 'font-weight' => 'font_weight', 'text-transform' => 'text_transform', 'letter-spacing' => 'letter_spacing', 'border-radius' => 'radius', 'padding' => 'padding');
        $element = $this->system->elements['button'] ?? $this->system->buttonVariation;
        $expected = $element[$fields[$property] ?? ''] ?? null;
        if (str_starts_with($property, 'padding-') && isset($element['padding'])) {
            $padding = $this->system->buttonPadding($element['padding']);
            $expected = $padding[substr($property, 8)] ?? null;
            // Compare the preset identity too: both paths must use the same
            // spacing tolerance, not compare a bound block to a raw length.
            if (null !== $expected && str_starts_with($expected, 'var:preset|spacing|')) {
                $slug = substr($expected, strlen('var:preset|spacing|'));
                return $value === $expected || $value === 'var(--wp--preset--spacing--' . $slug . ')'
                    || $this->system->spacingSlug($this->resolve($value)) === $slug;
            }
        }
        if (null === $expected || '' === $value) return false;
        $value = $this->resolve($value);
        if (in_array($property, array('color', 'background', 'background-color'), true)) return null !== CssColor::hex($value) && CssColor::hex($value) === CssColor::hex($expected);
        if ($value === $expected) return true;
        $a = CssLength::toPx($value, $this->system->rootPx);
        $b = CssLength::toPx($expected, $this->system->rootPx);
        return null !== $a && null !== $b && abs($a - $b) < 0.001;
    }

    private function bindRuleBody(CssStylesheetTransformer $transformer, string $body, bool $rootSelector = false): string
    {
        $result = '';
        foreach ($transformer->splitStyleRuleBody($body) as $part) {
            if (!isset($part['declarations'])) {
                $nested = '' === $part['at_rule'] || in_array($part['at_rule'], array('media', 'supports', 'container', 'layer', 'scope'), true);
                $result .= $part['prelude'] . '{' . ($nested ? $this->bindRuleBody($transformer, $part['body'], $rootSelector) : $part['body']) . '}';
                continue;
            }
            $declarations = $part['declarations'];
            $changed = false;
            $rewritten = array();
            foreach (CssValueSplitter::splitTopLevel($declarations, array(';')) as $declaration) {
                $bound = $this->bindDeclaration($declaration, $rootSelector);
                // Only source bytes are projected. Newly emitted preset reads
                // must keep WordPress's namespace so Global Styles owns them.
                $bound = $bound === $declaration ? self::sourcePresets($declaration) : $bound;
                $changed = $changed || $bound !== $declaration;
                $rewritten[] = $bound;
            }
            // An untouched run keeps its source bytes.
            $result .= $changed ? implode(';', $rewritten) . (str_ends_with(rtrim($declarations), ';') ? ';' : '') : $declarations;
        }
        return $result;
    }

    /** Keep source preset definitions and unbound reads out of core's namespace. */
    private static function sourcePresets(string $css): string
    {
        // Possessive runs keep PCRE from recursing per character, so a large
        // quoted value such as an SVG data URL is skipped instead of failing.
        return preg_replace_callback(
            '~/\*.*?\*/|"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|\'[^\'\\\\]*+(?:\\\\.[^\'\\\\]*+)*+\'|--wp--preset--[a-zA-Z0-9_-]+~s',
            static fn(array $match): string => str_starts_with($match[0], '--wp--preset--')
                ? '--be--source--preset--' . substr($match[0], strlen('--wp--preset--')) : $match[0],
            $css
        ) ?? $css;
    }

    private function bindDeclaration(string $declaration, bool $rootSelector = false): string
    {
        $parts = explode(':', $declaration, 2);
        if (2 !== count($parts)) return $declaration;
        $property = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        $important = '';
        if (1 === preg_match('/\s*!\s*important\s*$/i', $value, $match)) {
            $important = '!important';
            $value = trim(substr($value, 0, -strlen($match[0])));
        }
        if ('' === $value) return $declaration;
        if (str_starts_with($property, '--')) {
            $font = CssFontShorthand::expand($value, false, $familyOffset);
            if (isset($font['font-family'])) {
                $family = substr($value, $familyOffset);
                $slug = $this->exactFontFamilySlug($family);
                foreach ($this->system->fontFamilies as $preset) {
                    if (null !== $slug && $preset['slug'] === $slug && $this->sameFont($family, $preset['fontFamily'])) {
                        ++$this->bound;
                        return self::sourcePresets($parts[0]) . ':' . self::sourcePresets(substr($value, 0, $familyOffset))
                            . 'var(--wp--preset--font-family--' . $slug . ')' . $important;
                    }
                }
            }
        }
        if ((str_starts_with($property, '--') || in_array($property, array('background', 'background-image'), true)) && null === CssColor::parse($this->resolve($value))) {
            $bound = $this->bindColorTokens($value);
            return $bound === self::sourcePresets($value) ? $declaration : self::sourcePresets($parts[0]) . ':' . $bound . $important;
        }
        $kind = match (true) {
            in_array($property, array('color', 'background-color', 'background'), true), str_starts_with($property, '--') => 'color',
            'font-size' === $property => 'font-size',
            'font-family' === $property => 'font-family',
            default => null,
        };
        if (null === $kind) return $declaration;
        $slug = match ($kind) {
            'color' => $this->comparable('color', $this->resolve($value)) ? $this->system->colorSlug($this->resolve($value)) : null,
            'font-size' => $this->exactFontSizeSlug($value),
            default => $this->exactFontFamilySlug($value),
        };
        if (null === $slug && 'color' === $kind) {
            $mix = $this->translucentMix($value);
            if (null === $mix) return $declaration;
            ++$this->bound;
            return self::sourcePresets($parts[0]) . ':' . $mix . $important;
        }
        if (null === $slug) return $declaration;
        // Root-relative units on the root element resolve against the initial
        // font size, not the document's root, so such a preset would rescale it.
        if ($rootSelector && 'font-size' === $kind && self::rootRelativePreset($this->system->fontSizes, $slug)) return $declaration;
        ++$this->bound;
        return self::sourcePresets($parts[0]) . ':var(--wp--preset--' . ('color' === $kind ? 'color' : $kind) . '--' . $slug . ',' . self::sourcePresets($value) . ')' . $important;
    }

    /**
     * Bind literal colour tokens, descending only into gradients. Functions
     * such as var() and url() are indivisible: their contents are not stops.
     * Source fragments are projected before inserting generated preset reads.
     */
    private function bindColorTokens(string $value): string
    {
        $result = '';
        $offset = 0;
        foreach (CssValueSplitter::splitTopLevel($value, array(',')) as $part) {
            foreach (CssValueSplitter::splitTopLevelWhitespace($part) as $token) {
                $start = strpos($value, $token, $offset);
                if (false === $start) continue;
                $result .= self::sourcePresets(substr($value, $offset, $start - $offset));
                $replacement = null;
                // Resolve for membership just as whole-colour declarations do,
                // but retain the authored token as the rendering fallback.
                $resolved = $this->resolve($token);
                if (null !== CssColor::parse($resolved)) {
                    $slug = $this->system->colorSlug($resolved);
                    $replacement = null !== $slug ? 'var(--wp--preset--color--' . $slug . ',' . self::sourcePresets($token) . ')' : $this->translucentMix($token, true);
                    if (null !== $replacement) ++$this->bound;
                } elseif (1 === preg_match('/^((?:repeating-)?(?:linear|radial|conic)-gradient)\((.*)\)$/is', $token, $match) && CssValueSplitter::hasBalancedParens($token)) {
                    $replacement = $match[1] . '(' . $this->bindColorTokens($match[2]) . ')';
                }
                $result .= $replacement ?? self::sourcePresets($token);
                $offset = $start + strlen($token);
            }
        }
        return $result . self::sourcePresets(substr($value, $offset));
    }

    /**
     * A translucent colour whose opaque colour is a token, restated as the
     * token mixed with transparency: `rgb(245 241 232 / .7)` becomes
     * `color-mix(in srgb, var(--wp--preset--color--contrast,#f5f1e8) 70%, transparent)`.
     * Mixing with `transparent` in sRGB keeps the colour and scales its
     * alpha, so this renders the literal until the token is edited.
     */
    private function translucentMix(string $value, bool $includeTransparent = false): ?string
    {
        $resolved = $this->resolve($value);
        $color = CssColor::parse($resolved);
        if (null === $color) return null;
        $opaque = sprintf('#%02x%02x%02x', (int) round($color[0]), (int) round($color[1]), (int) round($color[2]));
        $translucent = $this->comparable('color', $resolved) ? $this->system->translucentColor($resolved) : null;
        // Transparent gradient stops still carry the palette colour's RGB.
        // Keep whole-value transparent declarations on their existing path.
        if ($includeTransparent && 0.0 === $color[3]) {
            $slug = $this->system->colorSlug($opaque);
            if (null !== $slug) $translucent = array('slug' => $slug, 'percent' => 0);
        }
        if (null === $translucent) return null;
        return 'color-mix(in srgb, var(--wp--preset--color--' . $translucent['slug'] . ',' . $opaque . ') ' . $translucent['percent'] . '%, transparent)';
    }

    /** Whether any selector in the list matches the root element itself (`html`, `:root`, optionally qualified). */
    private static function rootSelector(string $selector): bool
    {
        foreach (CssValueSplitter::splitTopLevel($selector, array(',')) as $part) {
            if (1 === preg_match('/^(?:html|:root)(?:\\[[^\\]]*\\]|::?[\\w-]+(?:\\([^)]*\\))?|[.#][\\w-]+)*$/i', trim($part))) return true;
        }
        return false;
    }

    /** @param list<array{slug:string,size:string}> $sizes */
    private static function rootRelativePreset(array $sizes, string $slug): bool
    {
        foreach ($sizes as $size) if ($size['slug'] === $slug) return 1 === preg_match('/\\d(?:r?em|%)/i', $size['size']);
        return false;
    }

    private function exactFontSizeSlug(string $value): ?string
    {
        $px = CssLength::toPx($value, $this->system->rootPx);
        if (null === $px) return null;
        foreach ($this->system->fontSizes as $size) if (abs($size['px'] - $px) < 0.01 && $size['slug'] === $this->system->fontSizeSlug($value)) return $size['slug'];
        return null;
    }

    private function exactFontFamilySlug(string $value): ?string
    {
        return $this->system->fontFamilySlug($this->resolve($value));
    }

    /**
     * The preset slug for a literal value, a class-driven observation, or
     * both. A literal is resolved through root custom properties; when that
     * fails, the value the source rendered for the block's class list stands
     * in for it. When both are known they must agree. Every media-condition
     * variant in the observation must match the same token.
     *
     * The reason a value with no slug is not bound is set in `$reason`
     * (see REFUSAL_REASONS); it stays null when there is nothing to bind.
     *
     * @param 'color'|'font-size'|'font-family'|'spacing' $kind
     */
    private function presetSlug(string $kind, ?string $literal, ?string $observation, ?string &$reason = null): ?string
    {
        $reason = null;
        if (null !== $observation && str_starts_with($observation, '!')) {
            $reason = self::OBSERVATION_REASONS[$observation] ?? null;
            return null;
        }
        $values = null === $observation || '' === $observation ? array() : explode(';', $observation);
        $resting = $values[0] ?? null;
        if (null !== $literal) {
            $resolved = $this->resolve($literal);
            if ($this->comparable($kind, $resolved)) {
                if (null !== $resting && $this->slug($kind, $resolved) !== $this->slug($kind, $resting)) {
                    $reason = 'inconsistent_source';
                    return null;
                }
                $resting = $resolved;
            } elseif (null === $resting) {
                $reason = self::unboundReason($kind, $resolved);
                return null;
            }
        }
        if (null === $resting) return null;
        $slug = $this->slug($kind, $resting);
        if (null === $slug) {
            $reason = self::unboundReason($kind, $resting);
            return null;
        }
        foreach (array_slice($values, 1) as $variant) {
            if ($this->slug($kind, $variant) === $slug) continue;
            $reason = 'breakpoint_variant';
            return null;
        }
        return $slug;
    }

    /** Why a value that names no token is not bound; null when it paints nothing of its own. */
    private static function unboundReason(string $kind, string $value): ?string
    {
        if ('color' !== $kind) return 'no_matching_token';
        if (1 === preg_match('/^(?:currentcolor|inherit|initial|unset|revert|revert-layer)$/i', trim($value))) return null;
        $color = CssColor::parse($value);
        if (null === $color) return 'unresolved_value';
        if ($color[3] <= 0.0) return null;
        return $color[3] < 1.0 ? 'alpha' : 'no_matching_token';
    }

    private function comparable(string $kind, string $value): bool
    {
        return match ($kind) {
            'color' => null !== CssColor::parse($value),
            'font-family' => '' !== trim($value) && !str_contains($value, 'var(') && !in_array(strtolower(trim($value)), array('inherit', 'initial', 'unset', 'revert', 'revert-layer'), true),
            default => null !== CssLength::toPx($value),
        };
    }

    private function slug(string $kind, string $value): ?string
    {
        if (!$this->comparable($kind, $value)) return null;
        return match ($kind) {
            'color' => $this->system->colorSlug($value),
            'font-size' => $this->system->fontSizeSlug($value),
            'font-family' => $this->system->fontFamilySlug($value),
            default => $this->system->spacingSlug($value),
        };
    }

    /**
     * The opening tag that carries the block's style support output.
     *
     * @return array{0:int,1:int,2:string}|null
     */
    private function styledTag(string $name, string $markup, int $after): ?array
    {
        if (in_array($name, self::LINK_BLOCKS, true)) {
            // The wrapper div comes first; the styled link must be its first
            // child, before any nested block delimiter.
            if (1 !== preg_match('/\G\s*<div\b[^>]*>\s*(<(?:a|button)\b[^>]*\bwp-block-button__link\b[^>]*>)/', $markup, $match, PREG_OFFSET_CAPTURE, $after)) return null;
            return array($match[1][1], $match[1][1] + strlen($match[1][0]), $match[1][0]);
        }
        if (1 !== preg_match('/\G\s*(<[a-z][a-z0-9-]*\b[^>]*>)/i', $markup, $match, PREG_OFFSET_CAPTURE, $after)) return null;
        return array($match[1][1], $match[1][1] + strlen($match[1][0]), $match[1][0]);
    }

    /**
     * Whether binding a property keeps the cascade outcome.
     *
     * A preset class is `!important`, so any other `!important` rule that can
     * reach the element competes with it; a rule that excludes one of the
     * classes the binding adds (`:not(.has-text-color)`) cannot. Spacing stays
     * inline (as a preset variable), so only selectors on the style text can
     * observe it, and only through a fragment longer than the property name,
     * unless the declaration is new. Interaction-state rules for the property
     * make the resting value one of several, so they block binding too.
     *
     * @param array<string,true> $tokens
     * @param list<string> $addedClasses
     */
    /**
     * Whether adding `has-background` keeps the block's padding: the block
     * already has the class, its core style does not pad it, or every side
     * is set by an inline declaration or an author rule for its class list.
     *
     * @param list<array{0:string,1:string}> $declarations
     * @param array<string,string> $observed
     */
    private function keepsPaddingWithBackground(string $name, string $classes, array $declarations, array $observed): bool
    {
        if (!in_array($name, self::BACKGROUND_PADDED_BLOCKS, true) || in_array('has-background', preg_split('/\s+/', $classes) ?: array(), true)) return true;
        foreach (self::SPACING_SIDES as $side) {
            $inline = array() !== array_filter($declarations, static fn(array $declaration): bool => in_array($declaration[0], array('padding', 'padding-' . $side), true));
            if (!$inline && '' === ($observed['padding-' . $side] ?? '')) return false;
        }
        return true;
    }

    private function safe(string $property, array $tokens, array $addedClasses, bool $staysInline = false, bool $addsDeclaration = false, ?string &$reason = null, bool $statesRendered = false): bool
    {
        $finalClasses = $tokens;
        foreach ($addedClasses as $class) $finalClasses['class:' . $class] = true;
        foreach (self::families($property) as $family) {
            $reason = 'state_variant';
            if (!$statesRendered && $this->stateMatches($family, $tokens)) return false;
            $reason = 'style_selector';
            foreach ($this->styleTextRules[$family] ?? array() as $rule) {
                if (null !== $rule['tokens'] && array() === array_intersect_key(array_flip($rule['tokens']), $tokens)) continue;
                if (array() !== array_intersect_key(array_flip($rule['negated']), $finalClasses)) continue;
                if (!$staysInline || $addsDeclaration || 1 !== preg_match('/^[a-z-]*:?$/', strtolower(trim($rule['fragment'], ' ;')))) return false;
            }
            if ($staysInline) continue;
            $reason = 'important';
            foreach ($this->importantRules[$family] ?? array() as $rule) {
                if (array() !== $rule['tokens'] && array() === array_intersect_key(array_flip($rule['tokens']), $tokens)) continue;
                if (array() !== array_intersect_key(array_flip($rule['negated']), $finalClasses)) continue;
                if (isset($tokens[self::KNOWN_ATTRIBUTES])) {
                    foreach ($rule['attributes'] as [$name, $value]) {
                        if (in_array($name, array('class', 'style'), true)) continue;
                        if (!isset($tokens['attr:' . $name . (null === $value ? '' : '=' . $value)])) continue 2;
                    }
                }
                return false;
            }
        }
        $reason = null;
        return true;
    }

    /**
     * Whether a button's literal colour is exactly what the `button` element
     * style renders (DesignSystem::unanimousElements()), so the block can
     * drop it and render through Global Styles. That needs the element value
     * to be this token and, when an interaction-state rule restyles the
     * colour, the element's `:hover` value too. An unconditional author class
     * that bindStylesheet() will bind to the same token also renders the
     * value, without requiring unanimous button colours. In either case a
     * preset class (`!important`) would mask the state rule; the other
     * checks of safe() still apply.
     *
     * @param array<string,true> $tokens
     */
    private function rendersThroughElement(string $name, string $key, string $slug, string $property, array $tokens): bool
    {
        if ('button' !== $name) return false;
        $element = $this->system->elements['button'] ?? array();
        $field = 'text' === $key ? 'color' : 'background';
        if ('' === $slug) return false;
        $throughClass = false;
        foreach ($this->classColors as $class => $properties) {
            if (!isset($tokens['class:' . $class]) && !isset($tokens['class:' . EngineMarker::subjectClassClass($class)])) continue;
            foreach ('background-color' === $property ? array('background-color', 'background') : array($property) as $candidate) {
                foreach ($properties[$candidate] ?? array() as $value) {
                    // The same resolver and token lookup as bindStylesheet():
                    // only a rule that will read this exact preset is evidence.
                    if ($this->slug('color', $this->resolve($value)) !== $slug) return false;
                    $throughClass = true;
                }
            }
        }
        if (!$throughClass) {
            if (!isset($element[$field]) || $this->system->colorSlug($element[$field]) !== $slug) return false;
            $stated = false;
            foreach (self::families($property) as $family) $stated = $stated || $this->stateMatches($family, $tokens);
            if ($stated && !isset($element['hover_' . $field])) return false;
        }
        return $this->safe($property, $tokens, array(), false, false, $reason, true);
    }

    /**
     * A carried unconditional class can render a literal even when it has no
     * palette token (transparent, for example). Require every matching class
     * declaration to agree; unresolved and conflicting values are not proof.
     * Keep the same cascade guards as the preset-backed fallback.
     *
     * @param array<string,true> $tokens
     */
    private function rendersThroughClass(string $name, string $property, string $literal, array $tokens): bool
    {
        if ('button' !== $name) return false;
        $color = CssColor::parse($this->resolve($literal));
        if (null === $color) return false;
        $found = false;
        foreach ($this->classColors as $class => $properties) {
            if (!isset($tokens['class:' . $class]) && !isset($tokens['class:' . EngineMarker::subjectClassClass($class)])) continue;
            foreach ('background-color' === $property ? array('background-color', 'background') : array($property) as $candidate) {
                foreach ($properties[$candidate] ?? array() as $value) {
                    if (CssColor::parse($this->resolve($value)) !== $color) return false;
                    $found = true;
                }
            }
        }
        return $found && $this->safe($property, $tokens, array(), false, false, $reason, true);
    }

    /**
     * A value with its `var(--name)` references resolved through custom
     * properties that are set once, unconditionally, on the document root
     * (`:root`, `html`, `:host`). Any other definition could give the
     * variable a different value under some block, so a value that reads
     * such a property stays as written, except that a fallback
     * (`var(--tw-text-opacity, 1)`) stands in for a property that every
     * definition sets to that same value: the element renders the fallback
     * whether or not one of them reaches it.
     */
    private function resolve(string $value): string
    {
        for ($depth = 0; $depth < 5 && str_contains($value, 'var('); $depth++) {
            $unresolved = false;
            $value = (string) preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*?)\s*)?\)/', function (array $match) use (&$unresolved): string {
                $resolved = $this->variables[$match[1]] ?? false;
                $fallback = $match[2] ?? '';
                if (false === $resolved && '' !== $fallback && ($this->uniformVariables[$match[1]] ?? null) === $fallback) return $fallback;
                if (false === $resolved) {
                    $unresolved = true;
                    return $match[0];
                }
                return $resolved;
            }, $value);
            if ($unresolved) break;
        }
        return $value;
    }

    /** @param list<string> $ancestors */
    private function indexVariables(string $prelude, string $body, array $ancestors): void
    {
        if (!preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER)) return;
        $selectors = array_map(static fn(string $selector): string => strtolower(trim($selector)), CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude));
        $root = array() === array_diff($selectors, array(':root', 'html', ':host')) && array() === array_filter($ancestors, static fn(string $ancestor): bool => 1 !== preg_match('/^@layer(?:\s|$)/', $ancestor));
        foreach ($matches as $match) {
            $name = (string) $match[1];
            $value = trim((string) $match[2]);
            // Any definition with another value, wherever it applies, could
            // give the variable that value under some block. Repeating the
            // root value (editor mirrors such as `:root :root.editor-styles-wrapper`)
            // changes nothing.
            if (str_contains(strtolower($value), '!important') || (array_key_exists($name, $this->variables) && $this->variables[$name] !== $value)) $this->variables[$name] = false;
            elseif ($root) $this->variables[$name] = $value;
            else $this->scopedVariables[$name][$value] = true;
        }
    }

    private function indexRule(string $prelude, string $body): void
    {
        $nested = str_contains($body, '{') ? (new CssStylesheetTransformer())->splitStyleRuleBody($body) : array(array('declarations' => $body));
        $important = array();
        $declared = array();
        foreach ($nested as $part) {
            if (isset($part['declarations'])) {
                foreach (CssValueSplitter::splitTopLevel((string) $part['declarations'], array(';')) as $declaration) {
                    $parts = explode(':', $declaration, 2);
                    if (2 !== count($parts)) continue;
                    foreach (self::families(strtolower(trim($parts[0]))) as $family) {
                        $declared[$family] = true;
                        if (1 === preg_match('/!\s*important\s*$/i', $parts[1])) $important[$family] = true;
                    }
                }
            } elseif (1 === preg_match(self::STATE_PSEUDO, (string) $part['prelude'])) {
                // A nested interaction state (`&:hover{…}`, Tailwind v4)
                // restyles the parent rule's subject.
                foreach (self::families(self::nestedProperties((string) $part['body'])) as $family) $this->indexState($prelude, $family);
            }
        }
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
            if (preg_match_all('/\[\s*style\s*[*^$~|]?=\s*["\']?([^"\'\]]*)/i', $selector, $matches)) {
                // Only a selector reading the subject's own style text is
                // limited to the subject's tokens; one on an ancestor could
                // read any block's.
                $subject = 1 === preg_match('/\[\s*style\b/i', self::subjectCompound($selector)) ? self::subjectTokens($selector) : null;
                $tokens = null === $subject || array() === $subject['tokens'] ? null : $subject['tokens'];
                foreach ($matches[1] as $fragment) foreach (array('color', 'background', 'font-size', 'font-family', 'padding', 'margin') as $family) {
                    if ('' === $fragment || str_contains(strtolower($fragment), $family) || str_contains($family, strtolower(trim($fragment, ' :;')))) {
                        $this->styleTextRules[$family][] = array('tokens' => $tokens, 'negated' => $subject['negated'] ?? array(), 'fragment' => (string) $fragment);
                    }
                }
            }
            if (!str_contains($selector, 'blocks-engine-richtext-marker') && in_array('tag:mark', self::subjectTokens($selector)['tokens'] ?? array(), true)) $this->markRules = true;
            if (1 === preg_match(self::STATE_PSEUDO, $selector)) {
                foreach ($declared as $family => $_) $this->indexState($selector, $family);
                continue;
            }
            if (array() === $important) continue;
            $subject = self::subjectTokens($selector);
            if (null === $subject) continue;
            foreach ($important as $family => $_) $this->importantRules[$family][] = $subject + array('attributes' => self::subjectAttributes($selector));
        }
    }

    /**
     * Whether a state-rule subject can match an element with these tokens:
     * the subject is universal or shares a token with the element, and, when
     * the element's own start tag is known, it carries every attribute the
     * subject requires (`[type="checkbox"]`). `class` and `style` are not
     * checked, since binding rewrites them.
     *
     * @param array<string,true> $tokens
     */
    private function stateMatches(string $family, array $tokens): bool
    {
        foreach ($this->subjectCandidates('state', $family, $this->stateTokens[$family] ?? array(), $tokens) as $subject) {
            if (self::stateSubjectApplies($subject, $tokens)) return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $subject
     * @param array<string,true> $tokens
     */
    private static function stateSubjectApplies(array $subject, array $tokens): bool
    {
        if (isset($subject['compound']) && !self::stateSubjectMatches($subject['compound'], $tokens)) return false;
        if (array() !== array_intersect_key(array_flip($subject['negated']), $tokens)) return false;
        if (array() !== $subject['tokens'] && array() === array_intersect_key(array_flip($subject['tokens']), $tokens)) return false;
        if (isset($tokens[self::KNOWN_ATTRIBUTES])) {
            foreach ($subject['attributes'] as [$name, $value]) {
                if (in_array($name, array('class', 'style'), true)) continue;
                if (!isset($tokens['attr:' . $name . (null === $value ? '' : '=' . $value)])) return false;
            }
        }
        return true;
    }

    /**
     * Rules that can apply to an element with these tokens, in source order.
     * A rule's subject compound requires its classes, ids and type, and one
     * alternative of each `:is()` / `:where()` (see stateSubjectMatches()).
     * Each rule is filed under tokens of which the element must carry at
     * least one, and only the buckets for the element's own tokens are
     * visited. Rules without such a requirement are always candidates.
     *
     * @param list<array<string,mixed>> $rules
     * @param array<string,true> $tokens
     * @return list<array<string,mixed>>
     */
    private function subjectCandidates(string $kind, string $family, array $rules, array $tokens): array
    {
        if (!isset($this->subjectBuckets[$kind][$family])) {
            $buckets = array();
            foreach ($rules as $index => $rule) {
                foreach (self::requiredSubjectTokens($rule['compound'] ?? null) ?? array('') as $token) $buckets[$token][] = $index;
            }
            $this->subjectBuckets[$kind][$family] = $buckets;
        }
        $buckets = $this->subjectBuckets[$kind][$family];
        $indexes = array_flip($buckets[''] ?? array());
        foreach ($tokens as $token => $_) {
            if (isset($buckets[$token])) $indexes += array_flip($buckets[$token]);
        }
        ksort($indexes);
        return array_map(static fn(int $index): array => $rules[$index], array_keys($indexes));
    }

    /**
     * Tokens of which an element must carry at least one for the compound
     * to match it, or null when the compound requires none.
     *
     * @param array<string,mixed>|null $compound
     * @return list<string>|null
     */
    private static function requiredSubjectTokens(?array $compound): ?array
    {
        if (null === $compound) return null;
        if (array() !== ($compound['classes'] ?? array())) return array('class:' . reset($compound['classes']));
        if (array() !== ($compound['ids'] ?? array())) return array('id:' . reset($compound['ids']));
        if (is_string($compound['type'] ?? null)) return array('tag:' . strtolower($compound['type']));
        foreach ($compound['any'] ?? array() as $group) {
            $tokens = array();
            foreach ($group['alternatives'] as $alternative) {
                $subjects = $alternative['compounds'];
                $required = array() === $subjects ? null : self::requiredSubjectTokens($subjects[array_key_last($subjects)]);
                if (null === $required) continue 2;
                array_push($tokens, ...$required);
            }
            if (array() !== $tokens) return array_values(array_unique($tokens));
        }
        return null;
    }

    private function indexState(string $prelude, string $family): void
    {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
            $resting = (string) preg_replace(self::STATE_PSEUDO, '', $selector);
            $subject = self::subjectTokens($resting);
            if (null === $subject) continue;
            $parsed = CssSelectorMatcher::parse($resting);
            if ($parsed['supported'] && array() !== $parsed['compounds']) {
                $subject['compound'] = $parsed['compounds'][array_key_last($parsed['compounds'])];
            }
            $this->stateTokens[$family][] = $subject + array('attributes' => self::subjectAttributes($selector));
        }
    }

    /**
     * Records the subject, specificity, source order and value of every
     * plain style rule that declares a colour, so a state-guarded literal can
     * be compared with the resting cascade of its element. A declaration
     * under a condition, a layer or a media-scoped stylesheet is recorded
     * with a null value: it is not a resting value every viewport renders.
     */
    private function indexRestingColors(string $prelude, string $body, bool $conditional): void
    {
        if (str_contains($body, '{')) return;
        $values = array();
        foreach (self::declarations($body) as [$property, $value]) {
            if (!in_array($property, array('color', 'background-color', 'background'), true)) continue;
            foreach (self::families($property) as $family) $values[$family][] = $conditional ? null : $value;
        }
        if (array() === $values) return;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
            if (1 === preg_match(self::STATE_PSEUDO, $selector)) continue;
            $subject = self::subjectTokens($selector);
            if (null === $subject) continue;
            $subject['attributes'] = self::subjectAttributes($selector);
            $subject['rank'] = null;
            $parsed = CssSelectorMatcher::parse($selector);
            if ($parsed['supported'] && array() !== $parsed['compounds']) {
                $compounds = $parsed['compounds'];
                $subject['compound'] = $compounds[array_key_last($compounds)];
                $combinators = $parsed['combinators'];
                if (count($compounds) > 1 && '>' === ($combinators[array_key_last($combinators)] ?? null)) $subject['parent'] = $compounds[count($compounds) - 2];
                $subject['depth'] = count($compounds);
                $weight = CssSelectorMatcher::specificityCounts($parsed);
                $subject['rank'] = array($weight['ids'], $weight['classes'], $weight['types']);
            }
            foreach ($values as $family => $list) {
                foreach ($list as $value) $this->restingColors[$family][] = $subject + array('value' => $value, 'order' => count($this->restingColors[$family] ?? array()));
            }
        }
    }

    /**
     * Whether the element's resting cascade paints exactly this colour: the
     * highest-ranked unconditional rule that certainly selects the element
     * declares it, every rule that could reach the element and outrank that
     * one declares it too, and no `!important` or style-text rule competes.
     * The literal then only restates what the stylesheet paints at rest, so
     * an inline preset variable for it renders the same as the literal,
     * without the `!important` preset class that would mask the state rule.
     * No resting rule, a different or conditional resting colour, or a
     * competitor keeps the literal.
     *
     * A button's block classes sit on its wrapper, the link's direct parent.
     * `$button` holds the link's own tokens and the wrapper's: a rule whose
     * parent compound requires an engine class the wrapper lacks cannot
     * reach the link, and a rule is certain only when the link and the
     * wrapper each satisfy their own compound.
     *
     * @param array<string,true> $tokens
     * @param array{0:array<string,true>,1:array<string,true>}|null $button
     */
    private function restsOn(string $property, string $literal, array $tokens, ?array $button): bool
    {
        // Pure over the indexed stylesheets, and asked again for every
        // repeat of the same block across documents.
        return $this->restsOnResults[serialize(array($property, $literal, $tokens, $button))] ??= $this->resolvesRestingColor($property, $literal, $tokens, $button);
    }

    /**
     * @param array<string,true> $tokens
     * @param array{0:array<string,true>,1:array<string,true>}|null $button
     */
    private function resolvesRestingColor(string $property, string $literal, array $tokens, ?array $button): bool
    {
        $color = CssColor::parse($this->resolve($literal));
        if (null === $color) return false;
        [$own, $parentTokens] = $button ?? array($tokens, null);
        foreach (self::families($property) as $family) {
            $reaching = array();
            $winner = null;
            foreach ($this->subjectCandidates('resting', $family, $this->restingColors[$family] ?? array(), $tokens) as $rule) {
                if (!self::stateSubjectApplies($rule, $tokens)) continue;
                if (null !== $parentTokens && isset($rule['parent']) && self::requiresAbsentEngineClass($rule['parent'], $parentTokens)) continue;
                $reaching[] = $rule;
                // Certain only when every compound is verified: the subject,
                // and for a button link its wrapper as the direct parent.
                $certain = null !== $rule['value'] && null !== $rule['rank'] && isset($rule['compound']) && self::certainlyMatches($rule['compound'], $own)
                    && (1 === $rule['depth'] || (2 === $rule['depth'] && isset($rule['parent']) && null !== $parentTokens && self::certainlyMatches($rule['parent'], $parentTokens)));
                if ($certain && (null === $winner || self::outranks($rule, $winner))) $winner = $rule;
            }
            if (null === $winner || CssColor::parse($this->resolve($winner['value'])) !== $color) return false;
            foreach ($reaching as $rule) {
                if (!self::outranks($rule, $winner)) continue;
                if (null === $rule['value'] || CssColor::parse($this->resolve($rule['value'])) !== $color) return false;
            }
        }
        return $this->safe($property, $tokens, array(), false, false, $reason, true);
    }

    /**
     * Cascade order of two resting rules: specificity, then source order. A
     * rule whose specificity is unknown is assumed to win.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $other
     */
    private static function outranks(array $rule, array $other): bool
    {
        if (null === $rule['rank']) return true;
        if (null === $other['rank']) return false;
        $order = $rule['rank'] <=> $other['rank'];
        return 0 !== $order ? 1 === $order : $rule['order'] > $other['order'];
    }

    /**
     * Whether a compound requires, on every path, an engine class
     * (`blocks-engine-…`, `be-…`) these tokens lack. Engine classes are only ever
     * written by the engine, so their absence is certain.
     *
     * @param array<string,mixed> $compound
     * @param array<string,true> $tokens
     */
    private static function requiresAbsentEngineClass(array $compound, array $tokens): bool
    {
        foreach ($compound['classes'] ?? array() as $class) {
            if ((str_starts_with($class, 'blocks-engine-') || str_starts_with($class, 'be-')) && !isset($tokens['class:' . $class])) return true;
        }
        foreach ($compound['any'] ?? array() as $group) {
            if (array() === $group['alternatives']) continue;
            foreach ($group['alternatives'] as $alternative) {
                $subjects = $alternative['compounds'];
                if (array() === $subjects || !self::requiresAbsentEngineClass($subjects[array_key_last($subjects)], $tokens)) continue 2;
            }
            return true;
        }
        return false;
    }

    /**
     * Whether a compound certainly selects an element with these tokens: its
     * type, classes and ids are present, one alternative of each `:is()` /
     * `:where()` certainly selects it, and each `:not()` excludes a type or
     * engine class the element certainly lacks. Attributes and structural
     * pseudo-classes are not proof.
     *
     * @param array<string,mixed> $compound
     * @param array<string,true> $tokens
     */
    private static function certainlyMatches(array $compound, array $tokens): bool
    {
        if (array() !== ($compound['attributes'] ?? array())) return false;
        if (null !== ($compound['nth_child'] ?? null) || !empty($compound['first_child']) || !empty($compound['last_child']) || !empty($compound['root'])) return false;
        if (is_string($compound['type'] ?? null) && !isset($tokens['tag:' . strtolower($compound['type'])])) return false;
        foreach (array('classes' => 'class:', 'ids' => 'id:') as $key => $prefix) {
            foreach ($compound[$key] ?? array() as $value) if (!isset($tokens[$prefix . $value])) return false;
        }
        foreach ($compound['any'] ?? array() as $group) {
            $certain = false;
            foreach ($group['alternatives'] as $alternative) {
                if (1 === count($alternative['compounds']) && self::certainlyMatches($alternative['compounds'][0], $tokens)) $certain = true;
            }
            if (!$certain) return false;
        }
        foreach ($compound['not'] ?? array() as $negation) {
            if (1 !== count($negation['compounds'] ?? array())) return false;
            $inner = $negation['compounds'][0];
            $otherType = is_string($inner['type'] ?? null) && !isset($tokens['tag:' . strtolower($inner['type'])]);
            if (!$otherType && !self::requiresAbsentEngineClass($inner, $tokens)) return false;
        }
        return true;
    }

    /**
     * Necessary subject constraints, including the subject of each :is/:where
     * alternative. Ancestor tokens inside those functions cannot establish a
     * match on the element itself. Unknown/relational constraints stay conservative.
     *
     * @param array<string,mixed> $compound
     * @param array<string,true> $tokens
     */
    private static function stateSubjectMatches(array $compound, array $tokens): bool
    {
        if (is_string($compound['type'] ?? null) && !isset($tokens['tag:' . strtolower($compound['type'])])) return false;
        foreach (array('classes' => 'class:', 'ids' => 'id:') as $key => $prefix) {
            foreach ($compound[$key] ?? array() as $value) if (!isset($tokens[$prefix . $value])) return false;
        }
        foreach ($compound['any'] ?? array() as $group) {
            $possible = false;
            foreach ($group['alternatives'] as $alternative) {
                $subjects = $alternative['compounds'];
                if (array() === $subjects || self::stateSubjectMatches($subjects[array_key_last($subjects)], $tokens)) $possible = true;
            }
            if (!$possible) return false;
        }
        return true;
    }

    /** Property names declared anywhere in a nested rule body, through further nesting and conditions. */
    private static function nestedProperties(string $body): string
    {
        $properties = array();
        foreach ((new CssStylesheetTransformer())->splitStyleRuleBody($body) as $part) {
            if (isset($part['declarations'])) {
                foreach (CssValueSplitter::splitTopLevel((string) $part['declarations'], array(';')) as $declaration) {
                    if (str_contains($declaration, ':')) $properties[] = strtolower(trim(explode(':', $declaration, 2)[0]));
                }
            } else {
                $properties[] = self::nestedProperties((string) $part['body']);
            }
        }
        return implode(' ', $properties);
    }

    /** The selector's last compound, after its final combinator. */
    private static function subjectCompound(string $selector): string
    {
        $selector = trim($selector);
        $depth = 0;
        $start = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ('\\' === $char) { $i++; continue; }
            if ('(' === $char || '[' === $char) $depth++;
            elseif (')' === $char || ']' === $char) $depth--;
            elseif (0 === $depth && (ctype_space($char) || '>' === $char || '+' === $char || '~' === $char)) $start = $i + 1;
        }
        return substr($selector, $start);
    }

    /**
     * Class, id and type tokens in a selector's subject compound, and the
     * classes it excludes with a simple `:not(.class…)`. Tokens are empty when
     * the subject has none (universal or attribute-only); null when the
     * selector targets a pseudo-element rather than the element itself.
     *
     * @return array{tokens:list<string>,negated:list<string>}|null
     */
    private static function subjectTokens(string $selector): ?array
    {
        // A pseudo-element subject styles that pseudo-element, never the
        // element itself (`_::-webkit-full-page-media` included).
        if (1 === preg_match('/(?:::[\w-]+(?:\([^()]*\))?|:(?:before|after|first-line|first-letter))\s*$/i', trim($selector))) return null;
        $compound = self::subjectCompound($selector);
        $negated = array();
        $compound = (string) preg_replace_callback('/:not\(\s*((?:\.(?:\\\\.|[\w-])+)+)\s*\)/', static function (array $match) use (&$negated): string {
            if (preg_match_all('/\.((?:\\\\.|[\w-])+)/', $match[1], $classes) && 1 === count($classes[1])) $negated[] = 'class:' . stripslashes($classes[1][0]);
            return '';
        }, $compound);
        $tokens = array();
        if (1 === preg_match(self::TYPE_SELECTOR, $compound, $match)) $tokens[] = 'tag:' . strtolower($match[1]);
        if (preg_match_all('/\.((?:\\\\.|[\w-])+)/', $compound, $matches)) foreach ($matches[1] as $class) $tokens[] = 'class:' . stripslashes($class);
        if (preg_match_all('/#((?:\\\\.|[\w-])+)/', $compound, $matches)) foreach ($matches[1] as $id) $tokens[] = 'id:' . stripslashes($id);
        return array('tokens' => $tokens, 'negated' => $negated);
    }

    /**
     * Attributes a selector's subject compound requires by presence or exact
     * value, lowercased, outside functional pseudo-classes. Other operators
     * (`^=`, `*=`, …) are not listed, so they never rule an element out.
     *
     * @return list<array{0:string,1:string|null}>
     */
    private static function subjectAttributes(string $selector): array
    {
        $compound = (string) preg_replace('/:[\w-]+(\((?:[^()]++|(?1))*\))/', '', self::subjectCompound($selector));
        $attributes = array();
        if (preg_match_all('/\[\s*([\w-]+)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([\w-]+))\s*(?:[is]\s*)?)?\]/i', $compound, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) $attributes[] = array(strtolower($match[1]), isset($match[2]) ? strtolower(stripslashes($match[2] . ($match[3] ?? '') . ($match[4] ?? ''))) : null);
        }
        return $attributes;
    }

    /** @return array<string,true> */
    private function tagTokens(string $html, string $classes, bool $startTagKnown = false): array
    {
        $tokens = array();
        if (1 === preg_match('/^<([a-z][a-z0-9-]*)/i', $html, $match)) $tokens['tag:' . strtolower($match[1])] = true;
        foreach (preg_split('/\s+/', trim($classes)) ?: array() as $class) if ('' !== $class) $tokens['class:' . $class] = true;
        $id = self::attribute($html, 'id');
        if (null !== $id && '' !== $id) $tokens['id:' . $id] = true;
        // Attributes come from the element's own start tag only, and only
        // when that tag is the authored one and parses completely.
        $attributes = $startTagKnown && 1 === preg_match('/^<[a-z][a-z0-9-]*((?:\s+[^\s"\'>\/=]+="[^"]*")*)\s*\/?>/i', $html, $match) ? self::tagAttributes($match[1]) : null;
        if (null !== $attributes) {
            $tokens[self::KNOWN_ATTRIBUTES] = true;
            foreach ($attributes as $name => $value) $tokens['attr:' . $name] = $tokens['attr:' . $name . '=' . strtolower($value)] = true;
        }
        return $tokens;
    }

    /** The author's classes on a block, sorted, without the ones WordPress or the engine add. */
    private static function authoredClassKey(string $className): string
    {
        $classes = array_values(array_unique(array_filter(preg_split('/\s+/', trim($className)) ?: array(), static fn(string $class): bool => '' !== $class && 1 !== preg_match(self::GENERATED_CLASS, $class))));
        sort($classes, SORT_STRING);
        return implode(' ', $classes);
    }

    /**
     * Combine every document's class-list observations. A property keeps its
     * observation only when every element with the class list, on every
     * document, made the same one; otherwise it is `!`. Absence counts as an
     * observation, so a value only some of those elements declare is not
     * bound.
     *
     * @param array<string,array<string,mixed>> $usageBySource
     * @return array<string,array<string,string>>
     */
    private static function mergeClassStyles(array $usageBySource): array
    {
        ksort($usageBySource, SORT_STRING);
        $observations = array();
        foreach ($usageBySource as $usage) {
            foreach (is_array($usage['class_styles'] ?? null) ? $usage['class_styles'] : array() as $row) {
                $key = self::authoredClassKey((string) ($row['classes'] ?? ''));
                if ('' === $key) continue;
                foreach (self::CLASS_STYLE_PROPERTIES as $property) {
                    foreach ((array) ($row['properties'][$property] ?? array('')) as $observation) $observations[$key][$property][(string) $observation] = true;
                }
            }
        }
        ksort($observations, SORT_STRING);
        $merged = array();
        foreach ($observations as $key => $properties) {
            foreach ($properties as $property => $values) {
                if (array('' => true) === $values) continue;
                $merged[(string) $key][$property] = 1 === count($values) ? (string) array_key_first($values) : '!';
            }
        }
        return $merged;
    }

    /** @return list<string> */
    private static function families(string $properties): array
    {
        $families = array();
        foreach (preg_split('/\s+/', strtolower(trim($properties))) ?: array() as $property) {
            if ('color' === $property) $families['color'] = true;
            elseif ('background' === $property || 'background-color' === $property) $families['background'] = true;
            elseif ('border' === $property || 'border-color' === $property || preg_match('/^border-(?:top|right|bottom|left)(?:-color)?$/', $property)) $families['border-color'] = true;
            elseif ('font' === $property) $families['font-size'] = $families['font-family'] = true;
            elseif ('font-size' === $property || 'font-family' === $property) $families[$property] = true;
            else foreach (array('padding', 'margin') as $family) if ($property === $family || str_starts_with($property, $family . '-')) $families[$family] = true;
        }
        return array_keys($families);
    }

    private static function attribute(string $html, string $name): ?string
    {
        if (1 !== preg_match('/\s' . $name . '="([^"]*)"/i', $html, $match)) return null;
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function withAttribute(string $html, string $name, ?string $value): string
    {
        $pattern = '/\s' . $name . '="[^"]*"/i';
        if (null === $value) return (string) preg_replace($pattern, '', $html, 1);
        $encoded = ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        if (1 === preg_match($pattern, $html)) return (string) preg_replace_callback($pattern, static fn(): string => $encoded, $html, 1);
        // Core's block wrappers write `class` before `style`.
        return (string) preg_replace_callback('/\s*\/?>$/', static fn(array $end): string => $encoded . $end[0], $html, 1);
    }

    /** @return list<array{0:string,1:string}> */
    /**
     * Properties whose value another device document writes inline through
     * capture device-inline evidence. They stay inline declarations, which
     * the runtime's per-device style can replace.
     *
     * @param list<array{0:string,1:string}> $declarations
     * @return array<string, true>
     */
    private function deviceOverriddenProperties(\stdClass $attributes, array $declarations): array
    {
        $evidence = $attributes->metadata->blocksEngineRuntimeAttributes->{CaptureEvidence::DEVICE_INLINE} ?? null;
        $devices = is_string($evidence) ? json_decode($evidence, true) : null;
        if (!is_array($devices)) return array();
        $base = array_column($declarations, 1, 0);
        $overridden = array();
        foreach ($devices as $deviceStyle) {
            if (!is_string($deviceStyle)) continue;
            $device = array_column(self::declarations($deviceStyle), 1, 0);
            foreach (array_keys($base + $device) as $property) {
                if (($base[$property] ?? null) !== ($device[$property] ?? null)) $overridden[$property] = true;
            }
        }
        return $overridden;
    }

    private static function declarations(string $style): array
    {
        $declarations = array();
        foreach (CssValueSplitter::splitTopLevel($style, array(';')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            $declarations[] = array(strtolower(trim($parts[0])), trim($parts[1]));
        }
        return $declarations;
    }

    /** @param list<array{0:string,1:string}> $declarations */
    private static function declarationText(array $declarations): string
    {
        return implode(';', array_map(static fn(array $declaration): string => $declaration[0] . ':' . $declaration[1], $declarations));
    }

    /** @param list<array{0:string,1:string}> $declarations */
    private static function removeDeclaration(array &$declarations, string $property, string $value): bool
    {
        $matches = array_keys(array_filter($declarations, static fn(array $declaration): bool => $declaration[0] === $property));
        if (1 !== count($matches) || $declarations[$matches[0]][1] !== $value) return false;
        array_splice($declarations, $matches[0], 1);
        return true;
    }

    /** @param list<array{0:string,1:string}> $declarations */
    private static function replaceDeclaration(array &$declarations, string $property, string $value, string $replacement): bool
    {
        $matches = array_keys(array_filter($declarations, static fn(array $declaration): bool => $declaration[0] === $property));
        if (1 !== count($matches) || $declarations[$matches[0]][1] !== $value) return false;
        $declarations[$matches[0]][1] = $replacement;
        return true;
    }

    /**
     * Rewrites an inline literal that equals a token to the token's inline
     * preset variable (`var:preset|color|primary`, which core serializes as
     * `var(--wp--preset--color--primary)`). Unlike a preset class this adds
     * no `!important` and keeps the declaration where it was, so it renders
     * exactly as before until the token changes in Global Styles.
     *
     * @param list<array{0:string,1:string}> $declarations
     */
    private function inlinePreset(\stdClass $group, string $key, string $kind, string $slug, array &$declarations, string $property, string $literal): bool
    {
        if ('color' === $kind && null === CssColor::parse($literal)) return false;
        $type = 'color' === $kind ? 'color' : $kind;
        if (!self::replaceDeclaration($declarations, $property, $literal, 'var(--wp--preset--' . $type . '--' . $slug . ')')) return false;
        $group->{$key} = 'var:preset|' . $type . '|' . $slug;
        return true;
    }

    /** Drop style groups the rewrite emptied, as core's serializer would never emit them. */
    private static function prune(\stdClass $attributes): void
    {
        if (isset($attributes->style->spacing->padding) && $attributes->style->spacing->padding instanceof \stdClass && array() === get_object_vars($attributes->style->spacing->padding)) unset($attributes->style->spacing->padding);
        foreach (array('color', 'typography', 'spacing', 'border') as $group) {
            if (isset($attributes->style->{$group}) && $attributes->style->{$group} instanceof \stdClass && array() === get_object_vars($attributes->style->{$group})) unset($attributes->style->{$group});
        }
        if (array() === get_object_vars($attributes->style)) unset($attributes->style);
    }

}
