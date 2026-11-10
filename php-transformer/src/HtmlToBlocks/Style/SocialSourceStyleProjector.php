<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

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
    public function __construct(private readonly StyleResolver $styles) {}

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    public function project(array $attrs, DOMElement $anchor, AuthorStyleAnalysis $author, SourceTargetProjectionState $targets, bool $fontOnly = false): array
    {
        $originalAttrs = $attrs;
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
            $content = $this->styles->verbatimCssDeclarations($body)['content'] ?? '';
            if (in_array(strtolower(trim($content)), array('', 'none', 'normal', 'initial', 'unset', '""', "''"), true)) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                if (!preg_match('/::?before$/', $selector)) continue;
                $subject = preg_replace('/::?before$/', '', $selector) ?? '';
                foreach ($anchor->getElementsByTagName('*') as $candidate) {
                    if (!in_array(strtolower($candidate->tagName), array('i', 'span'), true)) continue;
                    if (!$this->styles->matchesCssSelector($candidate, $subject)) continue;
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
                $pixels = $fontGlyph ? null : HtmlDimensionAttribute::pixels($glyph->getAttribute($dimension));
                if (null !== $pixels && 0 < $pixels) $hinted[$dimension] = $pixels . 'px';
            }
            // Core resets the anchor's svg to the anchor's color (four classes
            // and a type). Every authored glyph rule receives the same scope
            // above that reset; its own specificity, condition and order stay
            // authoritative among them. Size hints stay below authored rules.
            $scope = str_repeat(':not(.' . $author->classSpecificityShim() . ')', 4);
            if (array() !== $hinted) $targets->record(SourceDom::elementSelector($glyph), ':root :where(.' . $marker . $nativeGlyph . '):not(.' . $author->classSpecificityShim() . '):not(' . $author->specificityShim() . ')', $this->styles->cssDeclarationString($hinted));
            (new CssStylesheetTransformer())->visitStyleRules($author->combinedCss(), function (string $prelude, string $body, array $conditions) use ($glyph, $marker, $nativeGlyph, $fontGlyph, $properties, $author, $targets, $scope): void {
                $declarations = array_intersect_key($this->styles->verbatimCssDeclarations($body), $properties);
                if (array() === $declarations) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $subject = $fontGlyph ? preg_replace('/::?before$/', '', $selector) ?? $selector : $selector;
                    if (!$this->styles->matchesCssSelector($glyph, $subject)) continue;
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
            $inline = array_intersect_key($this->styles->verbatimCssDeclarations($glyph->getAttribute('style')), $properties);
            if (array() !== $inline) $targets->record(SourceDom::elementSelector($glyph), ':root .' . $marker . $nativeGlyph . $scope, $this->styles->cssDeclarationString($inline));
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
}
