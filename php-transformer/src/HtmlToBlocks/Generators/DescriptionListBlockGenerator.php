<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use LogicException;

/**
 * Builds the static companion block that fills Gutenberg's description-list gap.
 *
 * Gutenberg issue #4880 remains unresolved; the proposed core implementation in
 * stalled PR #20760 is not available to generated sites.
 */
final class DescriptionListBlockGenerator
{
    public const LOCAL_NAME = 'description-list';

    /**
     * @param Closure(string, array<string, mixed>): void $registerGeneratedBlock
     * @param Closure(): string $generatedBlockNamespace Resolves the consumer-owned namespace the block is emitted under.
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier = new SourceElementClassifier(),
        private readonly ?Closure $registerGeneratedBlock = null,
        private readonly ?Closure $generatedBlockNamespace = null
    ) {
    }

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Description List',
            'category' => 'text',
            'description' => 'A semantic description list with terms and descriptions.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'groups' => array( 'type' => 'array', 'default' => array() ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $namespace): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RawHTML = element.RawHTML;
    var RichText = blockEditor.RichText;
    var useEffect = element.useEffect;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function safeCssText( value ) {
        var probe = document.createElement( 'span' );
        probe.setAttribute( 'style', value || '' );
        return probe.style.cssText;
    }
    function markupAttributes( item ) {
        var output = '';
        if ( item.className ) { output += ' class="' + escapeAttribute( item.className ) + '"'; }
        if ( item.style ) { output += ' style="' + escapeAttribute( item.style ) + '"'; }
        Object.keys( item.attributes || {} ).forEach( function( name ) { output += ' ' + name + '="' + escapeAttribute( item.attributes[ name ] ) + '"'; } );
        return output;
    }
    function groupItems( group ) { return group.items || [].concat( ( group.terms || [] ).map( function( item ) { return Object.assign( { tagName: 'dt' }, item ); } ), ( group.descriptions || [] ).map( function( item ) { return Object.assign( { tagName: 'dd' }, item ); } ) ); }
    function markup( blockAttributes ) {
        var output = '<dl' + markupAttributes( blockAttributes ) + '>';
        ( blockAttributes.groups || [] ).forEach( function( group ) {
            var wrapper = group.wrapper;
            if ( wrapper ) { output += '<div' + markupAttributes( wrapper ) + '>'; }
            groupItems( group ).forEach( function( item ) { output += '<' + item.tagName + markupAttributes( item ) + '>' + ( item.content || '' ) + '</' + item.tagName + '>'; } );
            if ( wrapper ) { output += '</div>'; }
        } );
        return output + '</dl>';
    }
    function updateItem( props, groupIndex, collection, itemIndex, content ) {
        var groups = ( props.attributes.groups || [] ).map( function( group ) {
            var clone = {
                terms: ( group.terms || [] ).map( function( item ) { return Object.assign( {}, item ); } ),
                descriptions: ( group.descriptions || [] ).map( function( item ) { return Object.assign( {}, item ); } )
            };
            if ( group.items ) { clone.items = group.items.map( function( item ) { return Object.assign( {}, item ); } ); }
            if ( group.wrapper ) { clone.wrapper = Object.assign( {}, group.wrapper ); }
            return clone;
        } );
        groups[ groupIndex ][ collection ][ itemIndex ].content = content;
        props.setAttributes( { groups: groups } );
    }
    function updateOrderedItem( props, groupIndex, itemIndex, content ) {
        var groups = ( props.attributes.groups || [] ).map( function( group ) { return Object.assign( {}, group, { items: ( group.items || [] ).map( function( item ) { return Object.assign( {}, item ); } ) } ); } );
        groups[ groupIndex ].items[ itemIndex ].content = content;
        props.setAttributes( { groups: groups } );
    }
    function editorAttributes( item ) { return Object.assign( {}, item.attributes || {}, item.className ? { className: item.className } : {} ); }
    function edit( props ) {
        var children = [];
        var scope = 'be-description-list-' + String( props.clientId || 'block' ).replace( /[^a-zA-Z0-9_-]/g, '' );
        var rules = safeCssText( props.attributes.style ) ? '.' + scope + '{' + safeCssText( props.attributes.style ) + '}' : '';
        ( props.attributes.groups || [] ).forEach( function( group, groupIndex ) {
            var groupChildren = [];
            var wrapper = group.wrapper;
            if ( wrapper && safeCssText( wrapper.style ) ) { rules += '.' + scope + ' [data-be-description-list-group="' + groupIndex + '"]{' + safeCssText( wrapper.style ) + '}'; }
            groupItems( group ).forEach( function( item, itemIndex ) {
                var key = item.tagName + '-' + groupIndex + '-' + itemIndex;
                var css = safeCssText( item.style );
                if ( css ) { rules += '.' + scope + ' [data-be-description-list-item="' + key + '"]{' + css + '}'; }
                groupChildren.push( createElement( RichText, Object.assign( editorAttributes( item ), { tagName: item.tagName, value: item.content || '', 'data-be-description-list-item': key, key: key, onChange: function( content ) { if ( group.items ) { updateOrderedItem( props, groupIndex, itemIndex, content ); } else { updateItem( props, groupIndex, 'dt' === item.tagName ? 'terms' : 'descriptions', 'dt' === item.tagName ? itemIndex : itemIndex - ( group.terms || [] ).length, content ); } } } ) ) );
            } );
            if ( wrapper ) { children.push( createElement( 'div', Object.assign( editorAttributes( wrapper ), { 'data-be-description-list-group': groupIndex, key: 'group-' + groupIndex } ), groupChildren ) ); } else { children = children.concat( groupChildren ); }
        } );
        useEffect( function() {
            if ( ! rules ) { return undefined; }
            var sheet = document.createElement( 'style' );
            sheet.textContent = rules;
            document.head.appendChild( sheet );
            return function() { sheet.remove(); };
        }, [ rules ] );
        return createElement( 'dl', { className: [ props.attributes.className, scope ].filter( Boolean ).join( ' ' ) }, children );
    }
    function save( props ) { return createElement( RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;

        return array(
            'index.js' => str_replace(
                array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__'),
                array($namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $script
            ),
        );
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ),
            'assets' => $this->assets($namespace),
        );
    }

    /**
     * Preserve valid direct and div-grouped description lists as a static
     * companion block while retaining the existing direct-list group schema.
     *
     * @return array<string, mixed>|null
     */
    public function convert(DOMElement $list): ?array
    {
        $groups = array();
        $group = null;

        foreach ( $list->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tag = strtolower($child->tagName);
            if ( 'div' === $tag ) {
                if ( null !== $group ) {
                    $groups[] = $group;
                    $group = null;
                }
                $wrappedGroup = $this->wrappedGroup($child);
                if ( null === $wrappedGroup ) {
                    return null;
                }
                $groups[] = $wrappedGroup;
                continue;
            }
            if ( ! in_array($tag, array( 'dt', 'dd' ), true) || ! $this->itemSupportsRichText($child) ) {
                return null;
            }
            if ( 'dt' === $tag ) {
                if ( null === $group || array() !== $group['descriptions'] ) {
                    if ( null !== $group ) {
                        $groups[] = $group;
                    }
                    $group = array( 'terms' => array(), 'descriptions' => array() );
                }
                $group['terms'][] = $this->item($child);
                continue;
            }
            if ( 'dd' !== $tag || null === $group || array() === $group['terms'] ) {
                return null;
            }
            $group['descriptions'][] = $this->item($child);
        }

        if ( null !== $group ) {
            if ( array() === $group['descriptions'] ) {
                return null;
            }
            $groups[] = $group;
        }
        if ( array() === $groups ) {
            return null;
        }

        $register = $this->registerGeneratedBlock
            ?? throw new LogicException('DescriptionListBlockGenerator was not wired for conversion.');
        $namespaceResolver = $this->generatedBlockNamespace
            ?? throw new LogicException('DescriptionListBlockGenerator was not wired for conversion.');
        $namespace = $namespaceResolver();
        $register(self::class, $this->definition($namespace));

        $markup = $this->markup($list, $groups);
        return array(
            'blockName' => $namespace . '/' . self::LOCAL_NAME,
            'attrs' => array_filter(array(
                'className' => $list->getAttribute('class'),
                'style' => $list->getAttribute('style'),
                'groups' => $groups,
            ), static fn (mixed $value): bool => '' !== $value),
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    private function itemSupportsRichText(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return false;
            }

            $tag = strtolower($child->tagName);
            if ( 'a' !== $tag && 'br' !== $tag && ! $this->sourceElementClassifier->isInlineContentElement($tag) ) {
                return false;
            }
            foreach ( $child->attributes as $attribute ) {
                $attributeName = strtolower($attribute->name);
                if ( ! ( 'a' === $tag && in_array($attributeName, array( 'href', 'target', 'rel' ), true) ) && ! ( 'time' === $tag && 'datetime' === $attributeName ) ) {
                    return false;
                }
            }
            if ( ! $this->itemSupportsRichText($child) ) {
                return false;
            }
        }

        return true;
    }

    private function itemSupportsFlowContent(DOMElement $element): bool
    {
        $hasParagraph = false;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }
            if ( ! $child instanceof DOMElement || 'p' !== strtolower($child->tagName) ) {
                return false;
            }
            foreach ( $child->attributes as $attribute ) {
                if ( ! in_array(strtolower($attribute->name), array( 'class', 'style' ), true) ) {
                    return false;
                }
            }
            if ( ! $this->itemSupportsRichText($child) ) {
                return false;
            }
            $hasParagraph = true;
        }

        return $hasParagraph;
    }

    /** @return array<string, mixed>|null */
    private function wrappedGroup(DOMElement $wrapper): ?array
    {
        $items = array();
        $hasTerm = false;
        $hasDescription = false;
        foreach ( $wrapper->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), array( 'dt', 'dd' ), true) ) {
                return null;
            }
            $tag = strtolower($child->tagName);
            if ( 'dt' === $tag && ! $this->itemSupportsRichText($child) ) {
                return null;
            }
            if ( 'dd' === $tag && ! $this->itemSupportsRichText($child) && ! $this->itemSupportsFlowContent($child) ) {
                return null;
            }
            if ( 'dt' === $tag ) {
                if ( $hasTerm && ! $hasDescription ) {
                    // Multiple terms may describe the same following definition.
                } elseif ( $hasDescription ) {
                    $hasDescription = false;
                }
                $hasTerm = true;
            } elseif ( ! $hasTerm ) {
                return null;
            } else {
                $hasDescription = true;
            }
            $items[] = array_merge(array( 'tagName' => $tag ), $this->item($child));
        }

        if ( ! $hasDescription ) {
            return null;
        }

        return array(
            'wrapper' => $this->wrapper($wrapper),
            'items' => $items,
        );
    }

    /** @return array<string, string> */
    private function item(DOMElement $element): array
    {
        return array_filter(array(
            'content' => SourceDom::innerHtml($element),
            'className' => $element->getAttribute('class'),
            'style' => $element->getAttribute('style'),
        ), static fn (mixed $value): bool => '' !== $value);
    }

    /** @return array<string, mixed> */
    private function wrapper(DOMElement $element): array
    {
        $wrapper = array_filter(array(
            'className' => $element->getAttribute('class'),
            'style' => $element->getAttribute('style'),
        ), static fn (mixed $value): bool => '' !== $value);
        $attributes = array();
        foreach ( $element->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( $this->wrapperAttributeIsSafe($name) ) {
                $attributes[$name] = $attribute->value;
            }
        }
        if ( array() !== $attributes ) {
            $wrapper['attributes'] = $attributes;
        }
        return $wrapper;
    }

    private function wrapperAttributeIsSafe(string $name): bool
    {
        if ( in_array($name, array( 'id', 'role' ), true) || str_starts_with($name, 'aria-') ) {
            return true;
        }

        return str_starts_with($name, 'data-') && ! str_starts_with($name, 'data-wp-');
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function markup(DOMElement $list, array $groups): string
    {
        $markup = '<dl' . $this->markupAttributes(array(
            'className' => $list->getAttribute('class'),
            'style' => $list->getAttribute('style'),
        )) . '>';
        foreach ( $groups as $group ) {
            if ( isset($group['wrapper']) && is_array($group['wrapper']) ) {
                $markup .= '<div' . $this->markupAttributes($group['wrapper']) . '>';
                foreach ( $group['items'] ?? array() as $item ) {
                    $tag = $item['tagName'] ?? '';
                    $markup .= '<' . $tag . $this->markupAttributes($item) . '>' . ($item['content'] ?? '') . '</' . $tag . '>';
                }
                $markup .= '</div>';
                continue;
            }
            foreach ( $group['terms'] as $term ) {
                $markup .= '<dt' . $this->markupAttributes($term) . '>' . ($term['content'] ?? '') . '</dt>';
            }
            foreach ( $group['descriptions'] as $description ) {
                $markup .= '<dd' . $this->markupAttributes($description) . '>' . ($description['content'] ?? '') . '</dd>';
            }
        }
        return $markup . '</dl>';
    }

    /** @param array<string, mixed> $attributes */
    private function markupAttributes(array $attributes): string
    {
        $markup = '';
        foreach ( array( 'className' => 'class', 'style' => 'style' ) as $key => $name ) {
            if ( '' !== (string) ($attributes[$key] ?? '') ) {
                $markup .= ' ' . $name . '="' . htmlspecialchars((string) $attributes[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        foreach ( $attributes['attributes'] ?? array() as $name => $value ) {
            $markup .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $markup;
    }
}
