<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\NavigationBlockNormalizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Compiles a document's header and footer landmarks into reusable
 * template-part artifacts.
 */
final class GlobalShellArtifacts
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly PatternTreeConverter $tree,
        private readonly Runtime $runtime,
        private readonly HtmlTransformerSession $session,
        private readonly NavigationBlockNormalizer $navigationBlockNormalizer
    ) {
    }

    /**
     * Convert reusable document shell interiors through the same transformer
     * state as the full page so projected selector identities remain canonical.
     *
     * @return array<int, array<string, mixed>>
     */
    public function globalShellArtifacts(DOMElement $body, string $source, bool $removeFromContent = false, int $contextDepth = 0): array
    {
        $artifacts = array();
        $removals = array();
        // A shell compiled inside its source ancestor context sits below that
        // many single-child wrappers; the wrappers only supply style context.
        $container = $body;
        for ( $depth = 0; $depth < $contextDepth; ++$depth ) {
            $children = array();
            foreach ( $container->childNodes as $child ) if ( $child instanceof DOMElement ) $children[] = $child;
            if ( 1 !== count($children) ) return $artifacts;
            $container = $children[0];
        }
        foreach ( $container->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $area = ShellLandmarkPolicy::landmarkKind(strtolower($child->tagName), SourceDom::attr($child, 'role'));
            if ( ! in_array($area, array( 'header', 'footer' ), true) ) {
                continue;
            }
            // A document heading makes this a page introduction, not reusable chrome.
            // It remains editable with the page even when authored in a header landmark.
            if ( 'header' === $area && 0 < $child->getElementsByTagName('h1')->length && 0 === $child->getElementsByTagName('nav')->length ) {
                continue;
            }

            // The landmark converts like any other element, so its group
            // carries the same resolved block supports and carrier classes on
            // every route whether or not an app frame wraps it. That group is
            // the landmark box: the template part renders exactly it.
            $shellFallbacks = array();
            $converted = $this->tree->element($child, $shellFallbacks, true);
            $converted = null === $converted ? array() : (isset($converted['blockName']) ? array($converted) : $converted);
            $converted = $this->navigationBlockNormalizer->normalize($converted, $this->session->transformationProvenanceState()->sources(), $this->session->transformationProvenanceState()->sourceBaseHiddenStates(), $this->session->transformationProvenanceState()->sourceCollapsedWrapperHiddenStates());
            if ( 1 === count($converted) && 'core/group' !== ($converted[0]['blockName'] ?? null) && $area === ($converted[0]['attrs']['tagName'] ?? null) ) {
                // A landmark block of its own (a scroll-state carrier) is the box.
                $markup = $this->runtime->serializeBlocks($converted);
            } else {
                $wrapper = 1 === count($converted) && 'core/group' === ($converted[0]['blockName'] ?? null)
                    ? $converted[0]
                    : $this->blocks->createBlock('core/group', array(), $converted, $child);
                $wrapperAttrs = ArrayField::array($wrapper, 'attrs');
                $wrapperAttrs['tagName'] = $area;
                $markup = $this->runtime->serializeBlocks(array($this->blocks->createBlock('core/group', $wrapperAttrs, $wrapper['innerBlocks'] ?? array(), $child)));
            }
            if ( '' === trim($markup) ) {
                continue;
            }
            $artifacts[] = array(
                'source_path' => $source . '#' . $area,
                'slug' => $area,
                'title' => ucfirst($area),
                'area' => $area,
                'body_format' => 'blocks',
                'block_markup' => $markup,
                'source_selector' => strtolower($child->tagName),
                'source_hash' => hash('sha256', SourceDom::outerHtml($child)),
                'placement' => array('kind' => 'entry_shell', 'source_path' => $source, 'template_slugs' => array('front-page')),
            );
            // A successfully projected global shell is owned by the template part,
            // not duplicated in the entry page's post-content markup.
            if ($removeFromContent) $removals[] = $child;
        }

        foreach ($removals as $child) $child->parentNode?->removeChild($child);

        return $artifacts;
    }
}
