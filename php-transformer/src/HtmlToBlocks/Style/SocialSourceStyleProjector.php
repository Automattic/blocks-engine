<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSpecificityProjection;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\HtmlDimensionAttribute;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;
use DOMText;

/** Source glyph geometry and painted row separators on native dynamic social items. */
final class SocialSourceStyleProjector
{
    /** Marks a native social link that paints its source SVG over core's glyph. */
    public const ARTWORK_CLASS = 'blocks-engine-social-source-artwork';

    private const ARTWORK_SHAPES = array('svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'polygon', 'title', 'desc');
    private const ARTWORK_ATTRIBUTES = array('viewbox', 'x', 'y', 'width', 'height', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'points', 'transform', 'fill-rule', 'clip-rule');

    public function __construct(
        private readonly StyleResolver $styles,
        private readonly SourceStyleResolutionState $sourceStyles
    ) {}

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    public function project(array $attrs, DOMElement $anchor, AuthorStyleAnalysis $author, SourceTargetProjectionState $targets, bool $fontOnly = false): array
    {
        $originalAttrs = $attrs;
        $artwork = str_contains((string) ($attrs['className'] ?? ''), self::ARTWORK_CLASS);
        $fontOnly = $fontOnly && !$artwork;
        $marker = 'blocks-engine-social-source-item-' . substr(hash('sha256', $anchor->getNodePath() ?? ''), 0, 12);
        $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' ' . $marker);
        $glyph = null;
        foreach ($anchor->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), array('span', 'svg', 'img'), true)) { $glyph = $child; break; }
        }
        // A clipped accessible label can precede the artwork. Find the host
        // of captured generated content, not simply the first span. This also
        // covers icon-font <i> elements without guessing from vendor classes.
        $fontGlyph = false;
        (new CssStylesheetTransformer())->visitStyleRules($author->combinedCss(), function (string $prelude, string $body) use ($anchor, &$glyph, &$fontGlyph): void {
            if ($fontGlyph) return;
            $content = SourceDeclarations::verbatim($body)['content'] ?? '';
            if (in_array(strtolower(trim($content)), array('', 'none', 'normal', 'initial', 'unset', '""', "''"), true)) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                if (!preg_match('/::?before$/', $selector)) continue;
                $subject = preg_replace('/::?before$/', '', $selector) ?? '';
                foreach ($anchor->getElementsByTagName('*') as $candidate) {
                    if (!in_array(strtolower($candidate->tagName), array('i', 'span'), true)) continue;
                    if (!$this->sourceStyles->matches($candidate, $subject)) continue;
                    $glyph = $candidate;
                    $fontGlyph = true;
                    return;
                }
            }
        });
        if ($fontOnly && !$fontGlyph) return $originalAttrs;
        if ($glyph instanceof DOMElement) {
            if ($fontGlyph) {
                $attrs['className'] .= ' blocks-engine-social-source-glyph';
                $targets->record(SourceDom::elementSelector($glyph), ':root .' . $marker . '>a>svg', 'display:none');
                $defaults = array('font-size' => 'inherit', 'font-family' => 'inherit', 'font-style' => 'inherit', 'font-weight' => 'inherit', 'line-height' => 'inherit', 'color' => 'inherit', 'display' => 'inline', 'width' => 'auto', 'height' => 'auto', 'vertical-align' => 'baseline');
                $declarations = array();
                foreach ($defaults as $property => $default) $declarations[$property] = 'var(--blocks-engine-social-glyph-' . $property . ',' . $default . ')';
                $targets->record(SourceDom::elementSelector($glyph), ':root :where(.' . $marker . '>a)::before', $this->styles->cssDeclarationString($declarations));
            }
            $nativeGlyph = $fontGlyph ? '>a::before' : '>a>svg';
            $properties = array_fill_keys(array('font-size', 'font-family', 'font-style', 'font-weight', 'line-height', 'vertical-align', 'width', 'height', 'display', 'color', 'content', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left'), true);
            // Width and height attributes are presentational hints: they size
            // the artwork above Core's 1em glyph but below every author rule,
            // so record them first at Core's own weight.
            $hinted = array();
            foreach (array('width', 'height') as $dimension) {
                // Painted artwork sizes itself below any chosen preset (projectArtwork).
                $pixels = ($fontGlyph || $artwork) ? null : HtmlDimensionAttribute::pixels($glyph->getAttribute($dimension));
                if (null !== $pixels && 0 < $pixels) $hinted[$dimension] = $pixels . 'px';
            }
            // Core resets the anchor's svg to the anchor's color (four classes
            // and a type). Every authored glyph rule receives the same scope
            // above that reset; its own specificity, condition and order stay
            // authoritative among them. Size hints stay below authored rules.
            $scope = str_repeat(':not(.' . $author->classSpecificityShim() . ')', 4);
            if (array() !== $hinted) $targets->record(SourceDom::elementSelector($glyph), ':root :where(.' . $marker . $nativeGlyph . '):not(.' . $author->classSpecificityShim() . '):not(' . $author->specificityShim() . ')', $this->styles->cssDeclarationString($hinted));
            // Painted artwork takes its colour from the block's icon colour
            // setting, so a colour the source set on the glyph itself is not copied.
            $glyphOwnsColor = false;
            (new CssStylesheetTransformer())->visitStyleRules($author->combinedCss(), function (string $prelude, string $body, array $conditions) use ($glyph, $marker, $nativeGlyph, $fontGlyph, $properties, $author, $targets, $scope, $artwork, &$glyphOwnsColor): void {
                $declarations = array_intersect_key(SourceDeclarations::verbatim($body), $properties);
                if ($artwork && isset($declarations['color'])) {
                    $matches = false;
                    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                        $matches = $matches || $this->sourceStyles->matches($glyph, $selector);
                    }
                    $glyphOwnsColor = $glyphOwnsColor || $matches;
                    unset($declarations['color']);
                }
                if (array() === $declarations) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $subject = $fontGlyph ? preg_replace('/::?before$/', '', $selector) ?? $selector : $selector;
                    if (!$this->sourceStyles->matches($glyph, $subject)) continue;
                    $parsed = CssSelectorMatcher::parse($subject);
                    if (!$parsed['supported']) continue;
                    $ownPseudo = $fontGlyph && $subject !== $selector;
                    $projected = $declarations;
                    if ($fontGlyph && !$ownPseudo) {
                        $projected = array();
                        foreach ($declarations as $property => $value) $projected['--blocks-engine-social-glyph-' . $property] = $value;
                    }
                    // Pseudo-elements cannot occur inside :where(). Their
                    // own type weight is already the source's before weight.
                    $target = ':root :where(.' . $marker . ($ownPseudo ? '>a' : ($fontGlyph ? '>a' : $nativeGlyph)) . ')' . $scope . CssSpecificityProjection::shims($parsed, $author->specificityShim(), $author->classSpecificityShim(), $author->idSpecificityShim()) . ($ownPseudo ? '::before' : '');
                    $targets->record(SourceDom::elementSelector($glyph), $target, $this->styles->cssDeclarationString($projected), $conditions);
                }
            });
            $inline = array_intersect_key(SourceDeclarations::verbatim($glyph->getAttribute('style')), $properties);
            if ($artwork && isset($inline['color'])) {
                $glyphOwnsColor = true;
                unset($inline['color']);
            }
            if (array() !== $inline) $targets->record(SourceDom::elementSelector($glyph), ':root .' . $marker . $nativeGlyph . $scope, $this->styles->cssDeclarationString($inline));
            if ($artwork && 'svg' === strtolower($glyph->tagName)) $this->projectArtwork($glyph, $marker, $targets, $glyphOwnsColor);
        }
        $separator = '';
        for ($node = $anchor->nextSibling; $node instanceof DOMText; $node = $node->nextSibling) $separator .= $node->textContent;
        if ('' !== $separator && '' === trim(str_replace("\u{00a0}", ' ', $separator))) {
            $separator = preg_replace('/[\t\r\n ]+/', ' ', $separator) ?? $separator;
            $content = str_replace("\u{00a0}", '\\a0 ', $separator);
            $targets->record(SourceDom::elementSelector($anchor), ':root .' . $marker . '::after', 'content:"' . $content . '";white-space:normal');
        }
        return $attrs;
    }

    /** Filled shapes only, with one paint: the block's icon colour can then own it. */
    public static function isSingleColourArtwork(DOMElement $svg): bool
    {
        $paints = array();
        $nodes = array($svg);
        foreach ($svg->getElementsByTagName('*') as $child) $nodes[] = $child;
        foreach ($nodes as $node) {
            if (!in_array(strtolower($node->tagName), self::ARTWORK_SHAPES, true)) return false;
            $stroke = strtolower(trim($node->getAttribute('stroke')));
            if (('' !== $stroke && 'none' !== $stroke) || array() !== array_filter(CssRuleAnalyzer::declarations($node->getAttribute('style'), array('stroke')), static fn (array $declaration): bool => ! str_starts_with(CssValueInspector::comparable($declaration['value']), 'none'))) return false;
            $fill = self::artworkFill($node);
            if (str_starts_with($fill, 'url(')) return false;
            if (!in_array($fill, array('', 'currentcolor', 'none', 'inherit'), true)) $paints[$fill] = true;
        }
        return 1 >= count($paints);
    }

    /** The element's own fill; a style declaration wins over the attribute. */
    private static function artworkFill(DOMElement $node): string
    {
        $declaration = CssRuleAnalyzer::declarations($node->getAttribute('style'), array('fill'))[0] ?? null;
        if (null !== $declaration) return CssValueInspector::comparable($declaration['value']);
        return strtolower(trim($node->getAttribute('fill')));
    }

    private function projectArtwork(DOMElement $svg, string $marker, SourceTargetProjectionState $targets, bool $glyphOwnsColor): void
    {
        $source = SourceDom::elementSelector($svg);
        // The class sits on the li on the frontend and on the button in the editor.
        $glyph = ':root :is(.' . $marker . '>a,button.' . $marker . ')>svg';
        $mask = 'url("data:image/svg+xml,' . rawurlencode($this->artworkMarkup($svg, true)) . '") center/100% 100% no-repeat';
        $targets->record($source, $glyph, 'background-color:currentColor;-webkit-mask:' . $mask . ';mask:' . $mask);
        $targets->record($source, $glyph . '>*', 'display:none');
        // The source glyph's own colour hid any link colour and its hover, so
        // neither may now show through in place of the block's icon colour.
        if ($glyphOwnsColor) $targets->record($source, ':root .' . $marker . '>a', 'color:inherit!important');
        // HTML dimension rules: width="40px" means 40 CSS pixels.
        $width = HtmlDimensionAttribute::pixels($svg->getAttribute('width'));
        $height = HtmlDimensionAttribute::pixels($svg->getAttribute('height'));
        if (null === $width || null === $height || $width <= 0 || $height <= 0) return;
        $unset = ':not(.has-small-icon-size,.has-normal-icon-size,.has-large-icon-size,.has-huge-icon-size)';
        $targets->record($source, ':root .wp-block-social-links' . $unset . ' :is(.' . $marker . '>a,button.' . $marker . ')>svg', 'width:' . $this->pixelValue($width) . ';height:' . $this->pixelValue($height));
    }

    private function pixelValue(float $pixels): string
    {
        return rtrim(rtrim(number_format($pixels, 3, '.', ''), '0'), '.') . 'px';
    }

    /** Allowlisted geometry only; source event handlers and evidence stay behind. */
    private function artworkMarkup(DOMElement $node, bool $root = false): string
    {
        $tag = strtolower($node->tagName);
        $attributes = $root ? ' xmlns="http://www.w3.org/2000/svg"' : '';
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if ($root && in_array($name, array('width', 'height', 'x', 'y', 'transform'), true)) continue;
            if (!in_array($name, self::ARTWORK_ATTRIBUTES, true)) continue;
            $attributes .= ' ' . ('viewbox' === $name ? 'viewBox' : $name) . '="' . htmlspecialchars($attribute->value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
        }
        // The mask reads only opacity, but "none" must stay none and a shape's
        // own fill must survive a fill="none" root.
        $fill = self::artworkFill($node);
        if (!in_array($fill, array('', 'inherit'), true)) $attributes .= ' fill="' . ('none' === $fill ? 'none' : '#000') . '"';
        $inner = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && !in_array(strtolower($child->tagName), array('title', 'desc'), true)) $inner .= $this->artworkMarkup($child);
        }
        return '<' . $tag . $attributes . '>' . $inner . '</' . $tag . '>';
    }
}
