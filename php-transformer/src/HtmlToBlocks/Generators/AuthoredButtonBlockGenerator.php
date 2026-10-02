<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use DOMDocument;
use DOMElement;
use DOMText;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/**
 * Builds the static companion block for compact, editable native button controls.
 */
final class AuthoredButtonBlockGenerator
{
    public const LOCAL_NAME = 'authored-button';

    private const LABEL_TAGS = array('div', 'p', 'span', 'small', 'strong', 'b', 'em', 'i', 'u', 's');

    /** A bounded single-label chain keeps source selector ancestry without arbitrary HTML. */
    public static function labelWrappers(DOMElement $button): array
    {
        $wrappers = array();
        $parent = $button;
        for ($depth = 0; $depth < 16; ++$depth) {
            $children = array();
            $text = false;
            foreach ($parent->childNodes as $node) {
                if ($node instanceof DOMElement) $children[] = $node;
                if ($node instanceof DOMText && '' !== trim($node->textContent)) $text = true;
            }
            if (array() === $children) return $wrappers;
            if (1 !== count($children) || $text) return array();
            $child = $children[0];
            $tag = strtolower($child->tagName);
            if (!in_array($tag, self::LABEL_TAGS, true)) return array();
            $wrappers[] = array('tagName' => $tag, 'attributes' => self::wrapperAttributes($child));
            $parent = $child;
        }
        return array();
    }

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Button Control',
            'category' => 'widgets',
            'description' => 'An editable native button control.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'type' => array( 'type' => 'string', 'default' => 'submit' ),
                'id' => array( 'type' => 'string', 'default' => '' ),
                'name' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'text' => array( 'type' => 'string', 'default' => '' ),
                'labelWrappers' => array( 'type' => 'array', 'default' => array() ),
                'contentParts' => array( 'type' => 'array', 'default' => array() ),
                'sourceAttributes' => array( 'type' => 'object', 'default' => array() ),
                'disabled' => array( 'type' => 'boolean', 'default' => false ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $namespace): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var SelectControl = components.SelectControl;
    var ToggleControl = components.ToggleControl;
    var attributes = __BLOCK_ATTRIBUTES__;
    var labelTags = __LABEL_TAGS__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function styleObject( value ) { if ( ! value ) return undefined; return String( value ).split( ';' ).reduce( function( output, declaration ) { var separator = declaration.indexOf( ':' ); if ( separator < 1 ) return output; var name = declaration.slice( 0, separator ).trim(); var property = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } ); output[ property ] = declaration.slice( separator + 1 ).trim(); return output; }, {} ); }
    function buttonType( value ) { return [ 'button', 'reset', 'submit' ].indexOf( value ) !== -1 ? value : 'submit'; }
    function labelWrappers( attrs ) { return ( Array.isArray( attrs.labelWrappers ) ? attrs.labelWrappers : [] ).slice( 0, 16 ).filter( function( wrapper ) { return wrapper && labelTags.indexOf( String( wrapper.tagName || '' ).toLowerCase() ) !== -1; } ); }
    function labelProps( wrapper ) { var props = {}; Object.keys( wrapper.attributes || {} ).forEach( function( name ) { if ( /^(?:class|id|style|data-(?!wp-)[a-z0-9_.:-]+)$/.test( name ) ) props[ name ] = String( wrapper.attributes[ name ] ); } ); return props; }
    function isSourceAttributeName( name ) { return /^(?:data-(?!wp-)[a-z0-9_.:-]+|aria-(?!label$)[a-z0-9-]+)$/.test( name ) && name !== 'data-action' && name !== 'jsaction'; }
    function sourceAttributeMarkup( attrs ) { var output = ''; Object.keys( attrs.sourceAttributes || {} ).sort().forEach( function( name ) { if ( isSourceAttributeName( name ) ) output += ' ' + name + '="' + escapeAttribute( attrs.sourceAttributes[ name ] ) + '"'; } ); return output; }
    function contentParts( attrs ) { return ( Array.isArray( attrs.contentParts ) ? attrs.contentParts : [] ).slice( 0, 32 ); }
    function labelMarkup( attrs ) { return labelWrappers( attrs ).reverse().reduce( function( content, wrapper ) { var tag = String( wrapper.tagName ).toLowerCase(); var props = labelProps( wrapper ); var opening = '<' + tag; Object.keys( props ).forEach( function( name ) { opening += ' ' + name + '="' + escapeAttribute( props[ name ] ) + '"'; } ); return opening + '>' + content + '</' + tag + '>'; }, escapeAttribute( attrs.text ) ); }
    function wrapPart( part, inner ) { var tag = String( part.tagName || '' ).toLowerCase(); if ( labelTags.indexOf( tag ) === -1 ) return inner; var props = labelProps( part ); var opening = '<' + tag; Object.keys( props ).forEach( function( name ) { opening += ' ' + name + '="' + escapeAttribute( props[ name ] ) + '"'; } ); return opening + '>' + inner + '</' + tag + '>'; }
    function partText( part, attrs ) { return escapeAttribute( typeof part.text === 'string' ? part.text : ( part.text ? attrs.text : '' ) ); }
    function renderParts( parts, attrs ) { return ( parts || [] ).map( function( part ) { if ( ! part ) return ''; if ( typeof part.html === 'string' ) return part.html; if ( Array.isArray( part.parts ) ) return wrapPart( part, renderParts( part.parts, attrs ) ); if ( typeof part.text === 'string' || part.text === true ) return wrapPart( part, partText( part, attrs ) ); return ''; } ).join( '' ); }
    function innerMarkup( attrs ) { return contentParts( attrs ).length ? renderParts( contentParts( attrs ), attrs ) : labelMarkup( attrs ); }
    function textFields( parts, path, fields ) { ( parts || [] ).forEach( function( part, index ) { if ( ! part ) return; var here = path.concat( index ); if ( typeof part.text === 'string' ) fields.push( { path: here, value: part.text } ); if ( Array.isArray( part.parts ) ) textFields( part.parts, here.concat( 'parts' ), fields ); } ); return fields; }
    function writeText( parts, path, value ) { var clone = JSON.parse( JSON.stringify( parts ) ); var node = clone; path.forEach( function( step, index ) { if ( index === path.length - 1 ) node[ step ].text = value; else node = node[ step ]; } ); return clone; }
    function labelElement( attrs ) { if ( contentParts( attrs ).length ) return createElement( element.RawHTML, null, innerMarkup( attrs ) ); return labelWrappers( attrs ).reverse().reduce( function( content, wrapper ) { var props = labelProps( wrapper ); if ( props.class !== undefined ) { props.className = props.class; delete props.class; } if ( props.style !== undefined ) props.style = styleObject( props.style ); return createElement( String( wrapper.tagName ).toLowerCase(), props, content ); }, attrs.text || '' ); }
    function buttonProps( attrs ) { var props = { type: buttonType( attrs.type ), id: attrs.id || undefined, name: attrs.name || undefined, 'aria-label': attrs.ariaLabel || undefined, className: attrs.className || undefined, style: styleObject( attrs.style ), disabled: attrs.disabled }; Object.keys( attrs.sourceAttributes || {} ).sort().forEach( function( name ) { if ( isSourceAttributeName( name ) ) props[ name ] = String( attrs.sourceAttributes[ name ] ); } ); return props; }
    function markup( attrs ) { var output = '<button'; [ 'type', 'id', 'name', 'ariaLabel', 'className', 'style' ].forEach( function( key ) { var value = 'type' === key ? buttonType( attrs.type ) : attrs[ key ]; if ( value ) output += ' ' + ( 'className' === key ? 'class' : ( 'ariaLabel' === key ? 'aria-label' : key ) ) + '="' + escapeAttribute( value ) + '"'; } ); output += sourceAttributeMarkup( attrs ); if ( attrs.disabled ) output += ' disabled'; output += '>' + innerMarkup( attrs ) + '</button>'; return output; }
    function edit( props ) { var attrs = props.attributes; var fields = textFields( contentParts( attrs ), [], [] ); var labels = fields.length ? fields.map( function( field, index ) { return createElement( TextControl, { key: 'label-' + index, label: index ? 'Label ' + ( index + 1 ) : 'Label', value: field.value, onChange: function( value ) { props.setAttributes( { contentParts: writeText( contentParts( attrs ), field.path, value ) } ); } } ); } ) : [ createElement( TextControl, { label: 'Label', value: attrs.text || '', onChange: function( text ) { props.setAttributes( { text: text } ); } } ) ]; var button = createElement( 'button', buttonProps( attrs ), labelElement( attrs ) ); return createElement( element.Fragment, null, createElement.apply( null, [ InspectorControls, null, createElement.apply( null, [ PanelBody, { title: 'Button settings' } ].concat( labels, [ createElement( TextControl, { label: 'Accessible name', value: attrs.ariaLabel || '', onChange: function( ariaLabel ) { props.setAttributes( { ariaLabel: ariaLabel } ); } } ), createElement( TextControl, { label: 'Name', value: attrs.name || '', onChange: function( name ) { props.setAttributes( { name: name } ); } } ), createElement( SelectControl, { label: 'Type', value: buttonType( attrs.type ), options: [ 'submit', 'button', 'reset' ].map( function( type ) { return { label: type, value: type }; } ), onChange: function( type ) { props.setAttributes( { type: type } ); } } ), createElement( ToggleControl, { label: 'Disabled', checked: !!attrs.disabled, onChange: function( disabled ) { props.setAttributes( { disabled: disabled } ); } } ) ] ) ) ] ), button ); }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array(
            'index.js' => str_replace(array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__', '__LABEL_TAGS__'), array($namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), json_encode(self::LABEL_TAGS, JSON_THROW_ON_ERROR)), $script),
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = (string) ($attrs['type'] ?? 'submit');
        if ( ! in_array($type, array( 'button', 'reset', 'submit' ), true) ) {
            $type = 'submit';
        }
        $markup = '<button type="' . $escape($type) . '"';
        foreach ( array( 'id', 'name', 'ariaLabel', 'className', 'style' ) as $key ) {
            $value = (string) ($attrs[$key] ?? '');
            if ( '' !== $value ) {
                $markup .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : $key ) ) . '="' . $escape($value) . '"';
            }
        }
        $markup .= $this->sourceAttributeMarkup(is_array($attrs['sourceAttributes'] ?? null) ? $attrs['sourceAttributes'] : array());
        if ( ! empty($attrs['disabled']) ) {
            $markup .= ' disabled';
        }

        $parts = $this->contentPartsMarkup($attrs, $escape);
        if ( null !== $parts ) {
            return $markup . '>' . $parts . '</button>';
        }

        $label = $escape($attrs['text'] ?? '');
        foreach (array_reverse(array_slice(is_array($attrs['labelWrappers'] ?? null) ? $attrs['labelWrappers'] : array(), 0, 16)) as $wrapper) {
            $tag = strtolower((string) ($wrapper['tagName'] ?? ''));
            if (!in_array($tag, self::LABEL_TAGS, true)) continue;
            $opening = '<' . $tag;
            foreach (is_array($wrapper['attributes'] ?? null) ? $wrapper['attributes'] : array() as $name => $value) {
                if (1 === preg_match('/^(?:class|id|style|data-(?!wp-)[a-z0-9_.:-]+)$/', (string) $name)) {
                    $opening .= ' ' . $name . '="' . $escape($value) . '"';
                }
            }
            $label = $opening . '>' . $label . '</' . $tag . '>';
        }
        return $markup . '>' . $label . '</button>';
    }

    /** @return array<string, string> */
    public static function sourceSafeAttributes(DOMElement $element): array
    {
        $candidates = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( self::isSourceAttributeName($name) ) {
                $candidates[$name] = $attribute->nodeValue ?? '';
            }
        }
        if ( array() === $candidates ) {
            return array();
        }
        ksort($candidates);
        $safeHtml = SourceDom::safeFallbackHtmlString('<b' . SourceDom::htmlAttributeString($candidates) . '></b>');
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $safeHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $node = $loaded ? $document->getElementsByTagName('b')->item(0) : null;
        if ( ! $node instanceof DOMElement ) {
            return array();
        }
        $safe = SourceDom::htmlAttributes($node);
        ksort($safe);

        return $safe;
    }

    /** @return array<int, array<string, mixed>> */
    public static function contentParts(DOMElement $button): array
    {
        if ( array() !== self::labelWrappers($button) || 0 === SourceDom::childElementCount($button) ) {
            return array();
        }

        return array_slice(self::partsFrom($button, 0), 0, 32);
    }

    public static function isSourceAttributeName(string $name): bool
    {
        return 1 === preg_match('/^(?:data-(?!wp-)[a-z0-9_.:-]+|aria-(?!label$)[a-z0-9-]+)$/', $name)
            && ! in_array($name, array( 'data-action', 'jsaction' ), true);
    }

    /** @param array<string, mixed> $attributes */
    private function sourceAttributeMarkup(array $attributes): string
    {
        $safe = array();
        foreach ( $attributes as $name => $value ) {
            $name = strtolower((string) $name);
            if ( self::isSourceAttributeName($name) ) {
                $safe[$name] = (string) $value;
            }
        }
        ksort($safe);

        return SourceDom::htmlAttributeString($safe);
    }

    /** @param array<string, mixed> $attrs @param callable(mixed): string $escape */
    private function contentPartsMarkup(array $attrs, callable $escape): ?string
    {
        $parts = array_slice(is_array($attrs['contentParts'] ?? null) ? $attrs['contentParts'] : array(), 0, 32);
        if ( array() === $parts ) {
            return null;
        }

        return $this->renderParts($parts, $attrs, $escape);
    }

    /** @param array<int, mixed> $parts @param array<string, mixed> $attrs @param callable(mixed): string $escape */
    private function renderParts(array $parts, array $attrs, callable $escape): string
    {
        $markup = '';
        foreach ( $parts as $part ) {
            if ( ! is_array($part) ) {
                continue;
            }
            if ( is_string($part['html'] ?? null) ) {
                $markup .= $part['html'];
                continue;
            }
            if ( is_array($part['parts'] ?? null) ) {
                $markup .= $this->wrapPart($part, $this->renderParts($part['parts'], $attrs, $escape), $escape);
                continue;
            }
            if ( is_string($part['text'] ?? null) || true === ($part['text'] ?? null) ) {
                $literal = is_string($part['text'] ?? null) ? $part['text'] : (string) ($attrs['text'] ?? '');
                $markup .= $this->wrapPart($part, $escape($literal), $escape);
            }
        }

        return $markup;
    }

    /** @param array<string, mixed> $part @param callable(mixed): string $escape */
    private function wrapPart(array $part, string $inner, callable $escape): string
    {
        $tag = strtolower((string) ($part['tagName'] ?? ''));
        if ( ! in_array($tag, self::LABEL_TAGS, true) ) {
            return $inner;
        }
        $opening = '<' . $tag;
        foreach ( is_array($part['attributes'] ?? null) ? $part['attributes'] : array() as $name => $value ) {
            if ( 1 === preg_match('/^(?:class|id|style|data-(?!wp-)[a-z0-9_.:-]+)$/', (string) $name) ) {
                $opening .= ' ' . $name . '="' . $escape($value) . '"';
            }
        }

        return $opening . '>' . $inner . '</' . $tag . '>';
    }

    /** @return array<int, array<string, mixed>> */
    private static function partsFrom(DOMElement $element, int $depth): array
    {
        if ( $depth > 16 ) {
            $html = self::safeMarkup(SourceDom::outerHtml($element));

            return '' === $html ? array() : array( array( 'html' => $html ) );
        }
        $parts = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMText ) {
                if ( '' === trim($child->textContent ?? '') ) {
                    continue;
                }
                $parts[] = array( 'text' => $child->textContent );
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ( ! in_array($tag, self::LABEL_TAGS, true) ) {
                $html = self::safeMarkup(SourceDom::outerHtml($child));
                if ( '' !== $html ) {
                    $parts[] = array( 'html' => $html );
                }
                continue;
            }
            $inner = self::partsFrom($child, $depth + 1);
            if ( 1 === count($inner) && is_string($inner[0]['text'] ?? null) && ! isset($inner[0]['tagName'], $inner[0]['parts'], $inner[0]['html']) ) {
                $part = array( 'tagName' => $tag, 'text' => $inner[0]['text'] );
                $attributes = self::wrapperAttributes($child);
                if ( array() !== $attributes ) {
                    $part['attributes'] = $attributes;
                }
                $parts[] = $part;
                continue;
            }
            if ( array() === $inner ) {
                $html = self::safeMarkup(SourceDom::outerHtml($child));
                if ( '' !== $html ) {
                    $parts[] = array( 'html' => $html );
                }
                continue;
            }
            $part = array( 'tagName' => $tag, 'parts' => $inner );
            $attributes = self::wrapperAttributes($child);
            if ( array() !== $attributes ) {
                $part['attributes'] = $attributes;
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /** @return array<string, string> */
    private static function wrapperAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $element->attributes as $attribute ) {
            $name = strtolower($attribute->name);
            if ( 1 === preg_match('/^(?:class|id|style|data-(?!wp-)[a-z0-9_.:-]+)$/', $name) ) {
                $attributes[$name] = $attribute->value;
            }
        }

        return $attributes;
    }

    private static function safeMarkup(string $html): string
    {
        return SourceDom::safeFallbackHtmlString($html);
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array( 'name' => self::LOCAL_NAME, 'block_json' => $this->blockJson($namespace), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ), 'assets' => $this->assets($namespace) );
    }
}
