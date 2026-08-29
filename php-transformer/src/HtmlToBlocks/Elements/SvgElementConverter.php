<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Routes standalone SVGs to materialized, preserved, decorative, or fallback representations. */
final class SvgElementConverter
{
    public function __construct(
        private readonly SvgElementContext $context,
        private readonly SvgElementMaterializer $materializer
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

    /** @return array<string, mixed>|null */
    private function materializedBlock(DOMElement $element): ?array
    {
        if ( $this->materializer->svgNeedsPhrasingHost($element) ) {
            $imageMarkup = $this->materializer->inlineSvgRichTextImageMarkup($element);
            if ( null !== $imageMarkup ) {
                return $this->context->createBlock('core/paragraph', array( 'content' => $imageMarkup ), array(), $element);
            }
        }

        return $this->materializer->inlineSvgBlockFromElement($element);
    }
}
