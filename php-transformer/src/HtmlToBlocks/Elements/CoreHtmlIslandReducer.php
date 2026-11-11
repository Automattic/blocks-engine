<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMDocument;
use DOMElement;

/**
 * Replaces legacy core/html and core/freeform islands with native blocks when
 * their whole fragment compiles cleanly.
 */
final class CoreHtmlIslandReducer
{
    /**
     * @param Closure(string, array<string, string>): array{0: array<string, mixed>, 1: HtmlTransformerSession} $compileFragment
     *        Compiles a detached fragment with the given button source boxes and returns the result
     *        data with the fragment's session.
     */
    public function __construct(
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly SourceBlockAttributeProjector $sourceBlockAttributeProjector,
        private readonly HtmlTransformerSession $session,
        private readonly Closure $compileFragment
    ) {
    }

    /**
     * Reduce safe legacy core/html islands through the producer's native block
     * recognizers. An island is replaced only when its complete fragment maps
     * to native blocks with no fallback diagnostics; otherwise its serialized
     * payload remains untouched.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function reduce(array $blocks, ?DOMElement $sourceRoot = null): array
    {
        $reduced = array();
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            if (in_array($name, array('core/html', 'core/freeform'), true)) {
                $html = is_string($block['attrs']['content'] ?? null)
                    ? $block['attrs']['content']
                    : (is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '');
                $replacement = $this->fragmentBlocks($html, $sourceRoot);
                array_push($reduced, ...($replacement ?? array($block)));
                continue;
            }

            $children = ArrayField::array($block, 'innerBlocks');
            if (array() !== $children) {
                $reducedChildren = array();
                $childReplacements = array();
                foreach ($children as $child) {
                    $replacement = $this->reduce(array($child), $sourceRoot);
                    $childReplacements[] = $replacement;
                    array_push($reducedChildren, ...$replacement);
                }
                $block['innerBlocks'] = $reducedChildren;
                $innerContent = array();
                $childIndex = 0;
                foreach (ArrayField::array($block, 'innerContent') as $content) {
                    if (null !== $content) {
                        $innerContent[] = $content;
                        continue;
                    }
                    foreach ($childReplacements[$childIndex] ?? array() as $_) {
                        $innerContent[] = null;
                    }
                    ++$childIndex;
                }
                $block['innerContent'] = $innerContent;
                $block['innerHTML'] = implode('', array_filter($innerContent, 'is_string'));
            }
            $reduced[] = $block;
        }

        return $reduced;
    }

    /**
     * @return array<int, array<string, mixed>>|null Null keeps the original island.
     */
    private function fragmentBlocks(string $html, ?DOMElement $sourceRoot = null): ?array
    {
        if ('' === trim($html)) {
            return array();
        }
        if (preg_match('/<\s*(?:script|style|iframe|canvas|svg|form|input|select|textarea)\b/i', $html)) {
            return null;
        }
        $document = new DOMDocument();
        $loaded = HtmlSerialization::loadHtml($document, '<!doctype html><html><body>' . $html . '</body></html>');
        if ($loaded) {
            foreach ($document->getElementsByTagName('*') as $element) {
                if ($element instanceof DOMElement && $this->runtimeIslands->isRuntimeDomTarget($element)) {
                    return null;
                }
            }
        }

        // Island reduction reparses a detached fragment, so ancestor selectors
        // no longer match. Resolve box modifiers against the original source
        // and pass that evidence to the same button attribute projector.
        $sourceBoxes = array();
        if ( $loaded && $sourceRoot instanceof DOMElement ) {
            foreach ( $document->getElementsByTagName('a') as $anchor ) {
                if ( ! $anchor instanceof DOMElement || '' === SourceDom::attr($anchor, 'id') ) {
                    continue;
                }
                foreach ( $sourceRoot->getElementsByTagName('a') as $sourceAnchor ) {
                    if ( $sourceAnchor instanceof DOMElement && SourceDom::attr($sourceAnchor, 'id') === SourceDom::attr($anchor, 'id') ) {
                        $sourceBoxes[SourceDom::attr($anchor, 'id')] = $this->sourceBlockAttributeProjector->layoutNeutralButtonSourceBoxClasses($sourceAnchor);
                        break;
                    }
                }
            }
        }
        [ $data, $fragmentSession ] = ($this->compileFragment)($html, $sourceBoxes);
        $blocks = ArrayField::array($data, 'blocks');
        if (array() === $blocks || array() !== ($data['fallbacks'] ?? array())) {
            return null;
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || !str_starts_with((string) ($block['blockName'] ?? ''), 'core/') || in_array($block['blockName'] ?? '', array('core/html', 'core/freeform'), true)) {
                return null;
            }
        }

        if ( $this->session->hasAssetMaterializationState() ) {
            // Fragment-owned support remains in the compiler's structured
            // records until acceptance. Commit only records whose source
            // identities survive in the returned blocks; a rejected exploratory
            // compilation never reaches this boundary.
            $this->session->generatedSupportStylesheetState()->commitAcceptedFrom(
                $fragmentSession->generatedSupportStylesheetState(),
                $blocks
            );
        }

        return $blocks;
    }
}
