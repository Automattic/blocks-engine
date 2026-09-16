<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use LogicException;

/**
 * Builds static-render custom block definitions for source subtrees that
 * the {@see Classification\SubtreeClassifier} identifies as cohesive custom-block
 * content units which map to nothing native/Automattic.
 *
 * This is the producer link of the classify -> route -> generate chain
 * (epic #497, keystone #491): the classifier decides a `core/html`-fallback
 * subtree IS a `custom_block`, and this generator turns it into an installable
 * custom block. The output shape (`name`, `block_json`, `render`) exactly
 * matches what the SSI companion-plugin scaffolder consumes
 * (Static_Site_Importer_Companion_Plugin::scaffold()) and what
 * {@see \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload}
 * packages into `companion_plugin_payload.blocks[]`.
 *
 * First-slice design (conservative):
 *  - Static render: the editable content remains on the block reference, while
 *    each content-sensitive block type carries the same sanitized HTML as its
 *    render payload. This satisfies the companion payload's static-HTML contract
 *    without allowing different instances to share the wrong render.
 *  - GENERIC only: names derive deterministically from structure and sanitized
 *    content; titles derive from generic structure, never fixture/site strings.
 *  - Pure: no I/O, no global state; the same inputs always yield the same
 *    definition.
 */
final class CustomBlockGenerator
{
    /**
     * Block-editor category for generated blocks. `widgets` is the generic
     * catch-all category that always exists in a stock editor.
     */
    public const CATEGORY = 'widgets';

    /**
     * @param Closure(DOMElement): bool $isSafeTransparentCustomElement
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     * @param Closure(DOMElement): bool $hasAuthorSemanticMarker
     * @param Closure(list<DOMElement>, list<array<string, mixed>>, DOMElement): array<string, mixed> $layoutShellBlockForElements
     */
    public function __construct(
        private readonly ?SourceElementClassifier $sourceElementClassifier = null,
        private readonly ?ElementPresentationResolver $presentationResolver = null,
        private readonly ?SourceBlockCreator $createBlock = null,
        private readonly ?Closure $isSafeTransparentCustomElement = null,
        private readonly ?Closure $convertChildren = null,
        private readonly ?Closure $hasAuthorSemanticMarker = null,
        private readonly ?Closure $layoutShellBlockForElements = null
    ) {
    }

    /**
     * Build the block.json descriptor (as an array) for a generated block type.
     *
     * @param string $blockName Fully-qualified block name (`namespace/local`).
     * @param string $title     Human-readable, generically-derived title.
     * @return array<string, mixed>
     */
    public function blockJson(string $blockName, string $title): array
    {
        return array(
            'apiVersion' => 3,
            'name'       => $blockName,
            'title'      => $title,
            'category'   => self::CATEGORY,
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                // The captured, sanitized subtree markup. Editable, so the block
                // is a real content unit rather than frozen raw HTML.
                'content' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
            ),
            'supports'   => array(
                'html' => false,
            ),
            'render'     => 'file:./render.php',
        );
    }

    /**
     * Static render HTML for a generated block definition. The caller supplies
     * the already-sanitized content carried by that definition's references.
     */
    public function render(string $content): string
    {
        return $content;
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    function edit( props ) {
        var content = props.attributes.content || '';
        return createElement(
            'div',
            blockEditor.useBlockProps(),
            createElement( element.RawHTML, null, content ),
            props.isSelected && createElement( components.TextareaControl, {
                label: 'HTML',
                value: content,
                onChange: function( value ) { props.setAttributes( { content: value } ); }
            } )
        );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { content: { type: 'string', default: '' } },
        supports: { html: false },
        edit: edit,
        save: function() { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace('__BLOCK_NAME__', $blockName, $script) );
    }

    /**
     * Per-instance attributes for the self-closing block reference emitted in
     * the converted output. Carries only the captured content; no innerHTML.
     *
     * @return array<string, mixed>
     */
    public function referenceAttributes(string $content): array
    {
        return array(
            'content' => $content,
        );
    }

    /**
     * Custom elements and static legacy content containers are presentation-only
     * only when their host exposes no component API and every child can stand on
     * its own as a native block.
     * Explicit ARIA list topology is retained with semantic Group wrappers.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convert(DOMElement $element, array &$fallbacks): ?array
    {
        $sourceElementClassifier = $this->sourceElementClassifier ?? throw new LogicException('CustomBlockGenerator was not wired for conversion.');
        $presentationResolver = $this->presentationResolver ?? throw new LogicException('CustomBlockGenerator was not wired for conversion.');
        $createBlock = $this->createBlock ?? throw new LogicException('CustomBlockGenerator was not wired for conversion.');
        $isSafeTransparentCustomElement = $this->isSafeTransparentCustomElement ?? throw new LogicException('CustomBlockGenerator was not wired for conversion.');
        $convertChildren = $this->convertChildren ?? throw new LogicException('CustomBlockGenerator was not wired for conversion.');
        $hasAuthorSemanticMarker = $this->hasAuthorSemanticMarker ?? throw new LogicException('CustomBlockGenerator was not wired for conversion.');
        $layoutShellBlockForElements = $this->layoutShellBlockForElements ?? throw new LogicException('CustomBlockGenerator was not wired for conversion.');

        $tagName = strtolower($element->tagName);
        $isStaticContentContainer = 'content' === $tagName && ! $element->hasAttribute('select');
        if ( (! str_contains($tagName, '-') && ! $isStaticContentContainer) || ! $isSafeTransparentCustomElement($element) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            $children[] = $child;
        }
        if ( array() === $children ) {
            return null;
        }

        $isList = 'list' === strtolower(SourceDom::attr($element, 'role'));
        if ( $isList && ! array_reduce($children, static fn (bool $valid, DOMElement $child): bool => $valid && 'listitem' === strtolower(SourceDom::attr($child, 'role')), true) ) {
            return null;
        }
        if ( ! $isList && (1 !== count($children) || ! $sourceElementClassifier->isStructuralTransparentCustomWrapperChild($children[0])) ) {
            return null;
        }

        $converted = array();
        $childFallbacks = array();
        foreach ( $children as $child ) {
            if ( $isList && ! $isSafeTransparentCustomElement($child) ) {
                return null;
            }
            $childBlocks = $convertChildren($child, $childFallbacks);
            if ( array() === $childBlocks ) {
                return null;
            }
            if ( $isList ) {
                $converted[] = $createBlock->createBlock('core/group', array_merge($presentationResolver->presentationAttributes($child), array( 'tagName' => 'li' )), $childBlocks, $child);
            } else {
                array_push($converted, ...$childBlocks);
            }
        }
        if ( array() !== $childFallbacks ) {
            return null;
        }

        if ( $isList ) {
            return $createBlock->createBlock('core/group', array_merge($presentationResolver->presentationAttributes($element), array( 'tagName' => 'ul' )), $converted, $element);
        }

        if ( $hasAuthorSemanticMarker($element)
            || $hasAuthorSemanticMarker($children[0])
            || array() !== $presentationResolver->presentationAttributes($children[0])
        ) {
            return $layoutShellBlockForElements(array( $element, $children[0] ), $converted, $element);
        }

        if ( 1 === count($converted) && array() === $presentationResolver->presentationAttributes($element) ) {
            return $converted[0];
        }

        return $createBlock->createBlock('core/group', $presentationResolver->presentationAttributes($element), $converted, $element);
    }
}
