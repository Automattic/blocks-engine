<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Routes standalone SVGs to materialized, preserved, decorative, or fallback representations. */
final class SvgElementConverter implements ElementConverter
{
    public function __construct(
        private readonly SvgElementContext $context,
        private readonly SvgElementMaterializer $materializer,
        private readonly SvgTextRunLowering $textRun
    ) {
    }

    public function handles(string $tagName): bool
    {
        return 'svg' === $tagName;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        if ( $this->context->isInertHiddenStorage($element) ) {
            return ConversionOutcome::handled(null);
        }

        if ( $this->context->isRuntimeDomTarget($element) ) {
            $html = $this->context->sanitizeMarkup($element);
            if ( $this->context->isSafeContent($html) ) {
                return ConversionOutcome::handled($this->context->createBlock(
                    'core/html',
                    array( 'content' => $this->materializer->restoreSvgCasing($this->materializer->ensureInlineSvgBoxStyle($html, $element)) ),
                    array(),
                    $element
                ));
            }
        }

        // A definition carrier is document content even when aria-hidden or
        // positioned as decoration; do not discard it or wrap it as an image.
        if (SourceDom::isDefinitionsOnlySvg($element)) {
            $block = $this->materializer->inlineSvgBlockFromElement($element);
            if (null !== $block) {
                return ConversionOutcome::handled($block);
            }
        }

        if ($this->context->omitEmptyPaint($element)) {
            return ConversionOutcome::handled(null);
        }

        if ( ! $this->materializer->svgNeedsPhrasingHost($element) ) {
            $textRun = $this->textRun->block($element);
            if ( null !== $textRun ) {
                return ConversionOutcome::handled($textRun);
            }
        }

        if ( $this->isSafeEmptyMediaPlaceholder($element) ) {
            return ConversionOutcome::handled(null);
        }

        // Empty artwork may still occupy an authored inline media box. Keep
        // its baseline and dimensions instead of collapsing the container.
        if ( ! $element->hasChildNodes() && $element->hasAttribute('width') && $element->hasAttribute('height') ) {
            $html = $this->context->sanitizeMarkup($element);
            if ( $this->context->isSafeContent($html) ) {
                return ConversionOutcome::handled($this->context->createBlock('core/html', array('content' => $html), array(), $element));
            }
        }

        if ( $this->materializer->isSafeDecorativeSvgElement($element) ) {
            $isDecorativeChrome = $this->context->isVisualLayerElement($element);
            if ( ! $isDecorativeChrome && $this->context->hasDrawableContent($element) ) {
                $drawable = $this->materializedBlock($element);
                if ( null !== $drawable ) {
                    return ConversionOutcome::handled($drawable);
                }
            }
            if ( $this->context->isVisualLayerElement($element) ) {
                return ConversionOutcome::handled($this->context->createBlock(
                    'core/group',
                    $this->context->presentationAttributes($element),
                    array(),
                    $element
                ));
            }

            return ConversionOutcome::handled(null);
        }

        $block = $this->materializedBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }

        $this->context->captureFallback($element, $fallbacks);
        return ConversionOutcome::handled(null);
    }

    private function isSafeEmptyMediaPlaceholder(DOMElement $element): bool
    {
        if ( $element->hasChildNodes() ) {
            return false;
        }

        foreach ( $element->attributes as $attribute ) {
            if ( str_starts_with(strtolower($attribute->name), 'on') || preg_match('/javascript\s*:/i', $attribute->value) ) {
                return false;
            }
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        foreach ( array( 'img', 'picture', 'video' ) as $mediaTag ) {
            if ( 0 < $parent->getElementsByTagName($mediaTag)->length ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function materializedBlock(DOMElement $element): ?array
    {
        if ( $this->materializer->svgNeedsPhrasingHost($element) ) {
            $imageMarkup = $this->materializer->inlineSvgRichTextImageMarkup($element);
            if ( '' === $imageMarkup ) {
                return null;
            }
            if ( null !== $imageMarkup ) {
                return $this->context->createBlock('core/paragraph', array(
                    'content' => $imageMarkup,
                    'className' => SourceBlockAttributeProjector::SYNTHETIC_SVG_PARAGRAPH_CLASS,
                ), array(), $element);
            }
        }

        return $this->materializer->inlineSvgBlockFromElement($element);
    }
}
