<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorLayoutOwnership;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** Lowers a `nav` to a recognized navigation pattern, or a phrasing-only `nav` to a group around one paragraph. */
final class NavigationElementConverter implements ElementConverter
{
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly AuthorLayoutOwnership $authorLayoutOwnership,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime,
        private readonly SourceBlockAttributeProjector $sourceBlockAttributeProjector,
        private readonly StyleResolver $styleResolver,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'nav' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }

        $navigation = $this->tree->patterns($element, $fallbacks, array(AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class));
        if ( null !== $navigation ) {
            return ConversionOutcome::handled($this->session->runtimeBehaviorState()->rememberAccordionDisclosureRoot($navigation, $element));
        }

        $inlineNavigation = $this->inlineNavigationGroupBlock($element);

        return null === $inlineNavigation ? ConversionOutcome::unhandled() : ConversionOutcome::handled($inlineNavigation);
    }

    /** @return array<string, mixed>|null */
    private function inlineNavigationGroupBlock(DOMElement $element): ?array
    {
        if ( ! $this->sourceElementClassifier->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->authorLayoutOwnership->requiresStandaloneInlineLayoutLeaf($child) ) {
                return null;
            }
        }

        $content = $this->richTextMaterializer->content($element);
        $inlineSvgContent = $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content);
        if ( null !== $inlineSvgContent ) {
            $content = $inlineSvgContent;
        }
        if ( ! $this->runtime->hasVisibleText($content) || $this->richTextMaterializer->requiresHtmlFallbackWithoutNativeSvgImageObjects($content) ) {
            return null;
        }

        $paragraph = $this->createBlock->createBlock('core/paragraph', array_merge($this->sourceBlockAttributeProjector->syntheticInlineParagraphAttributes($element), array( 'content' => $content )));
        return $this->createBlock->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array( $paragraph ), $element);
    }
}
