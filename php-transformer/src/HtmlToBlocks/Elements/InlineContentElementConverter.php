<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutParticipation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** Converts phrasing-content elements to editable text or structural carriers. */
final class InlineContentElementConverter implements ElementConverter
{
    /** CSS Display 3 blockification of an inline-level layout item. */
    private const BLOCKIFIED_DISPLAY = array(
        'inline' => 'block',
        'inline-block' => 'block',
        'inline-flex' => 'flex',
        'inline-grid' => 'grid',
        'inline-table' => 'table',
    );

    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier $sourceElementClassifier,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization $richTextMaterializer,
        private readonly InlineContentElementContext $context,
        private readonly StyleResolver $styleResolver,
        private readonly Runtime $runtime,
        private readonly SourceBlockAttributeProjector $sourceAttributes
    ) {
    }

    public function handles(string $tagName): bool
    {
        return self::handlesTag($tagName);
    }

    public static function handlesTag(string $tagName): bool
    {
        return in_array($tagName, array( 'abbr', 'acronym', 'b', 'cite', 'code', 'del', 'em', 'font', 'i', 'ins', 'kbd', 'mark', 'rp', 'rt', 'ruby', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var' ), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        $socialLinks = $this->context->recognizePatterns($element, $fallbacks, array( SocialLinksPattern::class ));
        if ( null !== $socialLinks ) {
            return ConversionOutcome::handled($socialLinks);
        }

        // A single toggle plus the region it controls is one native disclosure
        // even when its wrapper is an inline span (a positioned trigger/popover
        // wrapper); otherwise the region lowers to an always-visible group.
        if ( 'span' === $tagName ) {
            $disclosure = $this->context->recognizePatterns($element, $fallbacks, array( DetailsPattern::class ));
            if ( null !== $disclosure ) {
                $this->context->rememberNativeDisclosureRoot($element);
                return ConversionOutcome::handled($disclosure);
            }
        }

        // A named graphic of images is one group of native images; the group
        // carries the graphic's accessible name.
        if ( SourceDom::isImageOnlyNamedGraphic($element) ) {
            $attrs = $this->styleResolver->presentationAttributes($element);
            $label = trim(SourceDom::attr($element, 'aria-label'));
            if ( '' !== $label ) {
                $attrs['ariaLabel'] = $label;
            }
            return ConversionOutcome::handled($this->createBlock->createBlock('core/group', $attrs, $this->context->convertChildren($element, $fallbacks), $element));
        }

        // Captured markup can place flow content inside an inline wrapper. A
        // native group retains that wrapper's presentation while its children
        // follow their own semantic conversion paths.
        if ( $this->sourceElementClassifier->hasFlowContentChildren($element) ) {
            $children = $this->context->convertChildren($element, $fallbacks);
            $attrs = $this->styleResolver->presentationAttributes($element);
            if ( $this->generatesNoBoxAroundFlowContent($element) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), SourceBlockAttributeProjector::INLINE_FLOW_WRAPPER_CLASS);
            }
            return ConversionOutcome::handled($this->createBlock->createBlock('core/group', $attrs, $children, $element));
        }

        if ( $this->context->isRuntimeDomTarget($element) ) {
            // An inline role-button (or a captured gallery direction control)
            // belongs to the button converter's editable runtime control.
            if ( AuthoredButtonBlockGenerator::canRetainRoleButton($element) ) {
                return ConversionOutcome::unhandled();
            }
            // Inline text a script finds by its hooks is saved verbatim in
            // paragraph rich text (RuntimeIslandAnalyzer::retainsRuntimeTextLeafInRichText()).
            if ( $this->context->retainsRuntimeTextLeafInRichText($element) ) {
                return ConversionOutcome::handled($this->createBlock->createBlock('core/paragraph', array('content' => SourceDom::outerHtml($element)), array(), $element));
            }
            return ConversionOutcome::handled($this->createBlock->htmlPreservationBlock($element));
        }

        $inlineSvgTextGroup = $this->context->inlineSvgTextGroupBlock($element);
        if ( null !== $inlineSvgTextGroup ) {
            return ConversionOutcome::handled($inlineSvgTextGroup);
        }

        if ( 0 < $element->getElementsByTagName('svg')->length && $this->runtime->hasVisibleText(SourceDom::outerHtml($element)) ) {
            $content = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, SourceDom::outerHtml($element));
            if ( null === $content || $this->richTextMaterializer->requiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
                return ConversionOutcome::handled($this->createBlock->htmlPreservationBlock($element));
            }

            return ConversionOutcome::handled($this->createBlock->createBlock('core/paragraph', array( 'content' => $content ), array(), $element));
        }

        if ( $this->context->ownsPositioningGeometry($element) ) {
            $carrier = $this->context->positionedInlineCarrierBlock($element, $fallbacks);
            if ( null !== $carrier ) {
                return ConversionOutcome::handled($carrier);
            }
        }

        if ( $this->context->hasAuthorSemanticMarker($element) ) {
            $content = SourceDom::innerHtml($element);
            if ( $this->runtime->hasVisibleText($content) ) {
                if ( $this->richTextMaterializer->hasStructuralHtml($content) ) {
                    $children = $this->context->convertChildren($element, $fallbacks);
                    if ( array() !== $children ) {
                        return ConversionOutcome::handled($this->group($element, $children));
                    }
                }
                return ConversionOutcome::handled($this->group($element, array(
                    $this->createBlock->createBlock('core/paragraph', array_merge($this->sourceAttributes->syntheticInlineParagraphAttributes($element), array( 'content' => $content ))),
                )));
            }
        }

        $richTextMarker = $this->context->richTextMarker($element);
        if ( '' !== $richTextMarker ) {
            if ( $this->sourceElementClassifier->hasBlockContentChildren($element) || $this->richTextMaterializer->hasStructuralHtml(SourceDom::innerHtml($element)) ) {
                $children = $this->context->convertChildren($element, $fallbacks);
                if ( array() !== $children ) {
                    return ConversionOutcome::handled($this->group($element, $children));
                }
            }
            // Resolve descendants while their source ancestry still exists;
            // detached RichText normalization cannot match scoped author rules.
            $content = $this->richTextMaterializer->content($element);
            if ( $this->runtime->hasVisibleText($content) ) {
                // The carrier keeps the author's hooks, so a media rule keeps
                // addressing it; an inline resting value would outrank that rule.
                $declarations = $this->styleResolver->stripResponsiveClassOwnedDeclarations($element, $this->richTextMaterializer->inlineVisualDeclarations($element));
                if ( 'transparent' === strtolower((string) ($declarations['-webkit-text-fill-color'] ?? '')) ) {
                    $declarations['color'] = 'transparent';
                }
                // A flex or grid parent blockified the source element, so its
                // own font set the item's line box. The mark keeps that box;
                // the carrier paragraph's inherited strut would not.
                $participation = LayoutParticipation::resolve($element, $this->styleResolver);
                if ( $participation->isLayoutItem() && isset(self::BLOCKIFIED_DISPLAY[$participation->sourceDisplay]) ) {
                    $declarations['display'] = self::BLOCKIFIED_DISPLAY[$participation->sourceDisplay];
                }
                $declarations['--blocks-engine-richtext-marker'] = $richTextMarker;
                $attrs = array(
                    'content' => '<mark style="' . htmlspecialchars($this->styleResolver->cssDeclarationString($declarations), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $content . '</mark>',
                );
                if ( 'none' === CssValueInspector::comparable((string) ($declarations['display'] ?? '')) ) {
                    $attrs['className'] = SourceBlockAttributeProjector::HIDDEN_RICH_TEXT_MARKER_CLASS;
                }
                return ConversionOutcome::handled($this->createBlock->createBlock('core/paragraph', $attrs, array(), $element));
            }
        }

        $dynamicText = $this->context->dynamicTextContent($element);
        if ( null !== $dynamicText ) {
            return ConversionOutcome::handled($this->createBlock->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $this->runtime->escapeHtml($dynamicText) )), array(), $element));
        }

        // Generated icon text is absent from textContent. Offer its source
        // markup to the same RichText mark lowering used for icons in text,
        // before the empty-inline omission path can discard it.
        if ('' === trim($element->textContent ?? '') && 0 === $element->childElementCount && $this->styleResolver->hasBeforeFontGlyph($element)) {
            $carrier = $element->cloneNode(true);
            $carrier->setAttribute('style', $this->styleResolver->cssDeclarationString($this->richTextMaterializer->inlineVisualDeclarations($element)));
            $content = SourceDom::outerHtml($carrier);
            if ($this->richTextMaterializer->requiresHtmlFallbackForElement($element, $content)) {
                $fallbacks[] = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackDiagnostic::build(array(
                    'type' => 'html',
                    'reason' => 'icon_font_glyph_unrepresentable',
                    'diagnostic_code' => 'icon_font_glyph_unrepresentable',
                    'message' => 'A generated font glyph could not be represented as editable RichText; its source HTML was preserved.',
                    'source_format' => 'html',
                    'tag' => $tagName,
                    'selector' => SourceDom::elementSelector($element),
                    'attributes' => SourceDom::htmlAttributes($element),
                ));
                return ConversionOutcome::handled($this->context->htmlPreservationBlock($element));
            }
            return ConversionOutcome::handled($this->sourceElementParagraph($element, $content, true));
        }

        $content = SourceDom::outerHtml($element);
        if ( ! $this->runtime->hasVisibleText($content) ) {
            $children = $this->context->convertChildren($element, $fallbacks);
            if ( 1 === count($children) && array() === $this->styleResolver->presentationAttributes($element) ) {
                return ConversionOutcome::handled($children[0]);
            }
            if ( array() !== $children ) {
                return ConversionOutcome::handled($this->group($element, $children));
            }
            $fragmentId = SourceDom::namedFragmentTargetId($element);
            if ( '' !== $fragmentId
                && SourceDom::documentReferencesFragmentId($element, $fragmentId)
                && ! SourceDom::documentHasOtherFragmentTarget($element, $fragmentId) ) {
                $attributes = $this->styleResolver->presentationAttributes($element);
                $attributes['anchor'] = $fragmentId;
                return ConversionOutcome::handled($this->createBlock->createBlock('core/group', $attributes, array(), $element));
            }
            if ( $this->context->shouldPreserveEmptyVisualElement($element) ) {
                return ConversionOutcome::handled($this->context->emptyVisualSpacerBlock($element));
            }
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::handled($this->sourceElementParagraph($element, $content));
    }

    /**
     * A paragraph whose rich text is the element's own markup.
     *
     * @return array<string, mixed>
     */
    private function sourceElementParagraph(DOMElement $element, string $content, bool $retainAlignment = false): array
    {
        $listItem = SourceDom::ancestorElement($element, 'li');
        $sourceElement = $this->richTextMaterializer->hasStructuralHtml($content)
            || 0 < $element->getElementsByTagName('svg')->length
            || ($listItem instanceof DOMElement && $this->context->isStructuralListItem($listItem))
            ? $element
            : null;
        $attrs = array('content' => $content);
        if (null === $sourceElement) $attrs = array_merge($this->sourceAttributes->syntheticInlineParagraphAttributes($element), $attrs);
        if ($retainAlignment) {
            $presentation = $this->styleResolver->presentationAttributes($element);
            if (isset($presentation['style']['typography']['textAlign'])) {
                $attrs['style']['typography']['textAlign'] = $presentation['style']['typography']['textAlign'];
            }
        }
        $paragraph = $this->createBlock->createBlock('core/paragraph', $attrs, array(), $sourceElement);
        // A synthetic paragraph registers no source, but still stands for this
        // one element: a finding may bind it by the element's selector.
        if (null === $sourceElement && is_array($paragraph)) $paragraph['_source_anchor_selector'] = SourceDom::elementSelector($element);
        return $paragraph;
    }

    /**
     * An inline box in block flow is never a containing block, and it
     * generates no box around block-level children: a child's `height:100%`
     * resolves against the nearest block ancestor beyond it. The group that
     * replaces it would be an auto-height block in between, so it stays
     * box-transparent unless the source gives the inline its own geometry
     * or paint, which a box-transparent group could not carry.
     */
    private function generatesNoBoxAroundFlowContent(DOMElement $element): bool
    {
        $participation = LayoutParticipation::resolve($element, $this->styleResolver);
        if ( ! $participation->isInline() || 'inline' !== $participation->sourceDisplay ) {
            return false;
        }
        foreach ( $this->styleResolver->resolvedSourceStateDeclarations($element) as $property => $value ) {
            $property = strtolower((string) $property);
            $value = CssValueInspector::comparable((string) $value);
            if ( 'position' === $property && ! in_array($value, array( '', 'static' ), true) ) return false;
            if ( in_array($property, array( 'transform', 'filter', 'opacity', 'isolation', 'z-index', 'contain' ), true) && ! in_array($value, array( '', 'none', 'auto', '1', 'normal' ), true) ) return false;
            if ( 1 === preg_match('/^(?:padding|margin|border|background|outline|box-shadow)/', $property) && ! in_array($value, array( '', '0', '0px', 'none', 'transparent', 'initial' ), true) ) return false;
        }
        return true;
    }

    /** @param array<int, array<string, mixed>> $children @return array<string, mixed> */
    private function group(DOMElement $element, array $children): array
    {
        return $this->createBlock->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
    }
}
