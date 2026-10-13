<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/**
 * Lowers an SVG whose only painted content is one plain `<text>` run into an
 * editable core/paragraph. Lettering drawn as SVG text (signatures, display
 * titles) is still text; RichText keeps it editable where a raw SVG cannot.
 *
 * The rule is deliberately narrow so the lowering is faithful: one `<text>`
 * with no nested runs or per-glyph placement, starting at the origin, under a
 * root with a definite scale. Everything else in the SVG must be non-painting
 * (definitions, metadata, or hidden shapes). A rectangle-only mask or clip on
 * the text is a reveal clip; captured mid-reveal it freezes the text hidden,
 * so it is dropped and recorded as frozen hidden state. The paragraph's line
 * box takes the SVG's rendered height; the baseline then follows the font's
 * metrics rather than the SVG `y`, so its provenance is classified as an
 * editable approximation.
 */
final class SvgTextRunLowering
{
    /** Elements that never paint on their own. */
    private const NON_PAINTING = array( 'defs', 'title', 'desc', 'metadata', 'mask', 'clippath', 'symbol', 'lineargradient', 'radialgradient', 'pattern', 'filter' );

    /** Text placement that a paragraph line cannot express. */
    private const PLACEMENT_ATTRIBUTES = array( 'transform', 'rotate', 'dx', 'dy', 'textlength', 'lengthadjust', 'filter', 'stroke', 'opacity', 'fill-opacity' );

    /** Inherited typography carried from the text run to the paragraph. */
    private const TYPOGRAPHY = array( 'font-family', 'font-weight', 'font-style', 'text-transform', 'text-decoration' );

    /**
     * @param Closure(string, DOMElement): string $resolveCssVariables
     * @param Closure(array<string, mixed>): void $recordFrozenHiddenState
     */
    public function __construct(
        private readonly ElementPresentationResolver $styles,
        private readonly Closure $resolveCssVariables,
        private readonly SourceBlockCreator $blocks,
        private readonly Closure $recordFrozenHiddenState
    ) {
    }

    /** @return array<string, mixed>|null */
    public function block(DOMElement $svg): ?array
    {
        $text = $this->soleTextRun($svg);
        $scale = null === $text ? null : $this->rootScale($svg);
        $height = null === $scale ? null : $this->renderedHeight($svg, $scale);
        if ( null === $text || null === $scale || null === $height ) {
            return null;
        }

        $declarations = array( 'margin-top' => '0', 'margin-bottom' => '0', 'line-height' => $this->px($height) );
        foreach ( array( 'font-size', 'letter-spacing' ) as $property ) {
            $value = $this->inherited($text, $svg, $property);
            if ( '' === $value ) {
                // An unset size inherits from the page, which only matches at unit scale.
                if ( 'font-size' === $property && 1.0 !== $scale ) {
                    return null;
                }
                continue;
            }
            $length = $this->pxLength($value);
            if ( null === $length ) {
                if ( 1.0 !== $scale ) {
                    return null;
                }
                $declarations[$property] = $value;
                continue;
            }
            $declarations[$property] = $this->px($length * $scale);
        }
        foreach ( self::TYPOGRAPHY as $property ) {
            $value = $this->inherited($text, $svg, $property);
            if ( '' !== $value ) {
                $declarations[$property] = $value;
            }
        }

        // SVG text paints with `fill`, which defaults to black rather than the
        // surrounding text color. Require an authored paint the paragraph can carry.
        $fill = ($this->resolveCssVariables)($this->inherited($text, $svg, 'fill'), $text);
        if ( '' === $fill || preg_match('/^(?:none|transparent|currentcolor|inherit|context-fill)$|url\s*\(|var\s*\(/i', $fill) ) {
            return null;
        }
        $declarations['color'] = $fill;

        $reveal = $this->revealClipReferences($svg, $text);
        if ( null === $reveal ) {
            return null;
        }
        if ( array() !== $reveal ) {
            ($this->recordFrozenHiddenState)(array(
                'tag'          => 'text',
                'selector'     => SourceDom::elementSelector($text),
                'declarations' => $reveal,
            ));
        }

        $mapped = (new StyleAttributeMapper())->map($declarations);
        $content = htmlspecialchars(trim((string) preg_replace('/\s+/u', ' ', $text->textContent)), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return $this->blocks->createBlock('core/paragraph', array_filter(array_merge($mapped['attrs'], array(
            'content' => $content,
            'style'   => $mapped['style'],
        ))), array(), $text);
    }

    /** The one painted element of the SVG when it is a plain text run at the origin. */
    private function soleTextRun(DOMElement $svg): ?DOMElement
    {
        $text = null;
        foreach ( $svg->getElementsByTagName('*') as $node ) {
            if ( ! $node instanceof DOMElement || $this->insideNonPainting($node, $svg) ) {
                continue;
            }
            $tag = strtolower($node->localName ?? $node->tagName);
            if ( in_array($tag, self::NON_PAINTING, true) ) {
                continue;
            }
            if ( 'g' === $tag ) {
                if ( '' !== $this->own($node, 'transform') || '' !== $this->own($node, 'opacity') || '' !== $this->own($node, 'filter') || '' !== $this->own($node, 'mask') || '' !== $this->own($node, 'clip-path') ) {
                    return null;
                }
                continue;
            }
            if ( 'text' !== $tag ) {
                if ( $this->isHidden($node) ) {
                    continue;
                }
                return null;
            }
            if ( null !== $text || $node->getElementsByTagName('*')->length > 0 || '' === trim($node->textContent) ) {
                return null;
            }
            foreach ( self::PLACEMENT_ATTRIBUTES as $property ) {
                $value = strtolower($this->own($node, $property));
                if ( '' !== $value && ! in_array($value, array( 'none', '1' ), true) ) {
                    return null;
                }
            }
            if ( $this->isHidden($node) || ! in_array(strtolower($this->own($node, 'text-anchor')), array( '', 'start' ), true) || '' !== $this->own($node, 'writing-mode') ) {
                return null;
            }
            if ( 0.0 !== ($this->pxLength($this->own($node, 'x')) ?? ( '' === $this->own($node, 'x') ? 0.0 : null )) ) {
                return null;
            }
            $text = $node;
        }

        return $text;
    }

    /**
     * Reveal masks/clips on the text, as dropped declarations; null when a
     * referenced mask or clip shapes the text beyond a rectangular reveal.
     *
     * @return array<string, string>|null
     */
    private function revealClipReferences(DOMElement $svg, DOMElement $text): ?array
    {
        $dropped = array();
        foreach ( array( 'mask', 'clip-path' ) as $property ) {
            $value = $this->own($text, $property);
            if ( '' === $value || 'none' === strtolower($value) ) {
                continue;
            }
            if ( ! preg_match('/^url\(\s*["\']?#([^"\')]+)["\']?\s*\)$/i', $value, $match) ) {
                return null;
            }
            $target = null;
            foreach ( $svg->getElementsByTagName('*') as $candidate ) {
                if ( $candidate instanceof DOMElement && $match[1] === $candidate->getAttribute('id') ) {
                    $target = $candidate;
                    break;
                }
            }
            if ( null === $target || ! in_array(strtolower($target->tagName), array( 'mask', 'clippath' ), true) ) {
                return null;
            }
            foreach ( $target->getElementsByTagName('*') as $shape ) {
                if ( ! $shape instanceof DOMElement ) {
                    continue;
                }
                $tag = strtolower($shape->tagName);
                if ( 'g' === $tag && '' === $this->own($shape, 'transform') ) {
                    continue;
                }
                $fill = strtolower(str_replace(' ', '', $this->own($shape, 'fill')));
                $opaqueWhite = 'clippath' === strtolower($target->tagName) || in_array($fill, array( '#fff', '#ffffff', 'white', 'rgb(255,255,255)' ), true);
                if ( 'rect' !== $tag || '' !== $this->own($shape, 'transform') || ! $opaqueWhite || '' !== $this->own($shape, 'opacity') ) {
                    return null;
                }
            }
            $dropped[$property] = $value;
        }

        return $dropped;
    }

    /** User-unit to CSS pixel scale fixed by the root's absolute width or height. */
    private function rootScale(DOMElement $svg): ?float
    {
        $box = $this->styles->structuralPresentationDeclarations($svg);
        if ( isset($box['width']) || isset($box['height']) ) {
            return null;
        }
        $viewBox = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox'))) ?: array();
        $width = $this->pxLength($svg->getAttribute('width'));
        $height = $this->pxLength($svg->getAttribute('height'));
        if ( 4 !== count($viewBox) ) {
            return null !== $width || null !== $height ? 1.0 : null;
        }
        $scales = array();
        if ( null !== $width && 0.0 < (float) $viewBox[2] ) {
            $scales[] = $width / (float) $viewBox[2];
        }
        if ( null !== $height && 0.0 < (float) $viewBox[3] ) {
            $scales[] = $height / (float) $viewBox[3];
        }

        return array() === $scales ? null : min($scales);
    }

    private function renderedHeight(DOMElement $svg, float $scale): ?float
    {
        $height = $this->pxLength($svg->getAttribute('height'));
        if ( null !== $height ) {
            return $height;
        }
        $viewBox = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox'))) ?: array();

        return 4 === count($viewBox) ? (float) $viewBox[3] * $scale : null;
    }

    private function insideNonPainting(DOMElement $node, DOMElement $svg): bool
    {
        for ( $parent = $node->parentNode; $parent instanceof DOMElement && $parent !== $svg; $parent = $parent->parentNode ) {
            if ( in_array(strtolower($parent->tagName), self::NON_PAINTING, true) ) {
                return true;
            }
        }

        return false;
    }

    private function isHidden(DOMElement $node): bool
    {
        return in_array(strtolower($this->own($node, 'display')), array( 'none' ), true)
            || in_array(strtolower($this->own($node, 'visibility')), array( 'hidden', 'collapse' ), true)
            || 0.0 === $this->pxLength($this->own($node, 'opacity'));
    }

    /** A property set on the element itself: cascaded CSS over its presentation attribute. */
    private function own(DOMElement $node, string $property): string
    {
        $declared = trim((string) preg_replace('/\s*!\s*important\s*$/i', '', (string) ($this->styles->structuralPresentationDeclarations($node)[$property] ?? '')));

        return '' !== $declared ? $declared : trim($node->getAttribute($property));
    }

    /** An inherited property from the text run up to (and including) the SVG root. */
    private function inherited(DOMElement $text, DOMElement $svg, string $property): string
    {
        for ( $node = $text; $node instanceof DOMElement; $node = $node->parentNode ) {
            $value = $this->own($node, $property);
            if ( '' !== $value && 'inherit' !== strtolower($value) ) {
                return $value;
            }
            if ( $node === $svg ) {
                break;
            }
        }

        return '';
    }

    private function pxLength(string $value): ?float
    {
        return preg_match('/^\s*(-?\d*\.?\d+)\s*(?:px)?\s*$/i', $value, $match) ? (float) $match[1] : null;
    }

    private function px(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') . 'px';
    }
}
