<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers shell header and footer landmarks to native groups when the session
 * preserves shell landmarks. Plain wrappers are transport, not sectioning
 * content, so document landmarks lower consistently and shared-shell identity
 * does not depend on them.
 */
final class ShellLandmarkConverter implements ElementConverter
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly PatternTreeConverter $tree,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $documentLandmark = ! SourceDom::hasAncestorTag($element, array('main', 'section', 'aside', 'nav'));
        if ( $this->session->preservesShellLandmarks()
            && in_array(ShellLandmarkPolicy::landmarkKind($tagName, SourceDom::attr($element, 'role')), array('header', 'footer'), true)
            && ($documentLandmark || SourceDom::hasAncestorTag($element, array('article')) || (SourceDom::hasAncestorTag($element, array('main', 'section')) && ShellLandmarkPolicy::isDocumentEdgeLandmark($element)))
        ) {
            $children = $this->tree->children($element, $fallbacks, true);
            if ( array() !== $children ) {
                $attrs = $this->styleResolver->presentationAttributes($element);
                if (SourceDom::hasAncestorTag($element, array('main', 'section')) && ShellLandmarkPolicy::isDocumentEdgeLandmark($element)) {
                    // Hoisting loses inherited text colour and the transparent
                    // landmark's ancestor backdrop. Keep that paint on the part.
                    foreach (array('text', 'background') as $paint) {
                        if (isset($attrs['style']['color'][$paint])) continue;
                        for ($ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
                            // Read resolved source paint before class-owned backgrounds
                            // are deferred to selectors that won't follow the detached part.
                            $context = $this->styleResolver->styleAttributeMapper()->map(
                                $this->styleResolver->presentationDeclarations($ancestor),
                                fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $ancestor)
                            );
                            if (!isset($context['style']['color'][$paint])) continue;
                            $attrs['style']['color'][$paint] = $context['style']['color'][$paint];
                            break;
                        }
                    }
                }
                return ConversionOutcome::handled($this->createBlock->createBlock('core/group', $attrs, $children, $element));
            }
        }

        return ConversionOutcome::unhandled();
    }
}
