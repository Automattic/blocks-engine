<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use DOMElement;
use DOMText;

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
            $attributes = array();
            foreach ($child->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (1 === preg_match('/^(?:class|id|style|data-(?!wp-)[a-z0-9_.:-]+)$/', $name)) {
                    $attributes[$name] = $attribute->value;
                }
            }
            $wrappers[] = array('tagName' => $tag, 'attributes' => $attributes);
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
                'ariaPressed' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'iconSvg' => array( 'type' => 'string', 'default' => '' ),
                'sourceAttributes' => array( 'type' => 'array', 'default' => array() ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'text' => array( 'type' => 'string', 'default' => '' ),
                'labelWrappers' => array( 'type' => 'array', 'default' => array() ),
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
    function pressed( value ) { return [ 'true', 'false', 'mixed' ].indexOf( String( value || '' ) ) !== -1 ? String( value ) : ''; }
    function safeIcon( value ) { value = String( value || '' ); return /^<svg[\s>]/i.test( value ) && ! /(?:<\/?(?:script|style|foreignobject|iframe|object|embed|link)\b|\son[a-z]+\s*=|javascript\s*:)/i.test( value ) ? value : ''; }
    function sourceAttributes( attrs ) { return ( Array.isArray( attrs.sourceAttributes ) ? attrs.sourceAttributes : [] ).filter( function( item ) { return item && /^data-(?!wp-)[a-z0-9_.:-]+$/.test( String( item.name || '' ) ); } ).sort( function( a, b ) { return String( a.name ).localeCompare( String( b.name ) ); } ); }
    function labelWrappers( attrs ) { return ( Array.isArray( attrs.labelWrappers ) ? attrs.labelWrappers : [] ).slice( 0, 16 ).filter( function( wrapper ) { return wrapper && labelTags.indexOf( String( wrapper.tagName || '' ).toLowerCase() ) !== -1; } ); }
    function labelProps( wrapper ) { var props = {}; Object.keys( wrapper.attributes || {} ).forEach( function( name ) { if ( /^(?:class|id|style|data-(?!wp-)[a-z0-9_.:-]+)$/.test( name ) ) props[ name ] = String( wrapper.attributes[ name ] ); } ); return props; }
    function labelMarkup( attrs ) { return labelWrappers( attrs ).reverse().reduce( function( content, wrapper ) { var tag = String( wrapper.tagName ).toLowerCase(); var props = labelProps( wrapper ); var opening = '<' + tag; Object.keys( props ).forEach( function( name ) { opening += ' ' + name + '="' + escapeAttribute( props[ name ] ) + '"'; } ); return opening + '>' + content + '</' + tag + '>'; }, escapeAttribute( attrs.text ) ); }
    function labelElement( attrs ) { return labelWrappers( attrs ).reverse().reduce( function( content, wrapper ) { var props = labelProps( wrapper ); if ( props.class !== undefined ) { props.className = props.class; delete props.class; } if ( props.style !== undefined ) props.style = styleObject( props.style ); return createElement( String( wrapper.tagName ).toLowerCase(), props, content ); }, attrs.text || '' ); }
    function markup( attrs ) { var output = '<button'; [ 'type', 'id', 'name', 'ariaLabel' ].forEach( function( key ) { var value = 'type' === key ? buttonType( attrs.type ) : attrs[ key ]; if ( value ) output += ' ' + ( 'ariaLabel' === key ? 'aria-label' : key ) + '="' + escapeAttribute( value ) + '"'; } ); var state = pressed( attrs.ariaPressed ); if ( state ) output += ' aria-pressed="' + state + '"'; [ 'className', 'style' ].forEach( function( key ) { var value = attrs[ key ]; if ( value ) output += ' ' + ( 'className' === key ? 'class' : key ) + '="' + escapeAttribute( value ) + '"'; } ); sourceAttributes( attrs ).forEach( function( item ) { output += ' ' + item.name + '="' + escapeAttribute( item.value ) + '"'; } ); if ( attrs.disabled ) output += ' disabled'; output += '>' + safeIcon( attrs.iconSvg ) + labelMarkup( attrs ) + '</button>'; return output; }
    function edit( props ) { var attrs = props.attributes; var state = pressed( attrs.ariaPressed ); var icon = safeIcon( attrs.iconSvg ); var button = createElement( 'button', { type: buttonType( attrs.type ), id: attrs.id || undefined, name: attrs.name || undefined, 'aria-label': attrs.ariaLabel || undefined, 'aria-pressed': state || undefined, className: attrs.className || undefined, style: styleObject( attrs.style ), disabled: attrs.disabled }, icon ? createElement( element.RawHTML, null, icon ) : null, labelElement( attrs ) ); return createElement( element.Fragment, null, createElement( InspectorControls, null, createElement( PanelBody, { title: 'Button settings' }, createElement( TextControl, { label: 'Label', value: attrs.text || '', onChange: function( text ) { props.setAttributes( { text: text } ); } } ), createElement( TextControl, { label: 'Accessible name', value: attrs.ariaLabel || '', onChange: function( ariaLabel ) { props.setAttributes( { ariaLabel: ariaLabel } ); } } ), createElement( SelectControl, { label: 'Pressed', value: state, options: [ { label: 'Unset', value: '' }, { label: 'true', value: 'true' }, { label: 'false', value: 'false' }, { label: 'mixed', value: 'mixed' } ], onChange: function( ariaPressed ) { props.setAttributes( { ariaPressed: ariaPressed } ); } } ), createElement( TextControl, { label: 'Name', value: attrs.name || '', onChange: function( name ) { props.setAttributes( { name: name } ); } } ), createElement( SelectControl, { label: 'Type', value: buttonType( attrs.type ), options: [ 'submit', 'button', 'reset' ].map( function( type ) { return { label: type, value: type }; } ), onChange: function( type ) { props.setAttributes( { type: type } ); } } ), createElement( ToggleControl, { label: 'Disabled', checked: !!attrs.disabled, onChange: function( disabled ) { props.setAttributes( { disabled: disabled } ); } } ) ) ), button ); }
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
        foreach ( array( 'id', 'name', 'ariaLabel' ) as $key ) {
            $value = (string) ($attrs[$key] ?? '');
            if ( '' !== $value ) {
                $markup .= ' ' . ( 'ariaLabel' === $key ? 'aria-label' : $key ) . '="' . $escape($value) . '"';
            }
        }
        $pressed = strtolower(trim((string) ($attrs['ariaPressed'] ?? '')));
        if ( in_array($pressed, array( 'true', 'false', 'mixed' ), true) ) {
            $markup .= ' aria-pressed="' . $pressed . '"';
        }
        foreach ( array( 'className', 'style' ) as $key ) {
            $value = (string) ($attrs[$key] ?? '');
            if ( '' !== $value ) {
                $markup .= ' ' . ( 'className' === $key ? 'class' : $key ) . '="' . $escape($value) . '"';
            }
        }
        foreach ( $this->sourceAttributes($attrs) as $attribute ) {
            $markup .= ' ' . $attribute['name'] . '="' . $escape($attribute['value']) . '"';
        }
        if ( ! empty($attrs['disabled']) ) {
            $markup .= ' disabled';
        }

        $label = $this->safeIconSvg((string) ($attrs['iconSvg'] ?? '')) . $escape($attrs['text'] ?? '');
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

    /** @param array<string, mixed> $attrs @return list<array{name: string, value: string}> */
    private function sourceAttributes(array $attrs): array
    {
        $attributes = array();
        foreach ( is_array($attrs['sourceAttributes'] ?? null) ? $attrs['sourceAttributes'] : array() as $attribute ) {
            $name = strtolower((string) ($attribute['name'] ?? ''));
            if ( 1 !== preg_match('/^data-(?!wp-)[a-z0-9_.:-]+$/', $name) ) {
                continue;
            }
            $attributes[$name] = (string) ($attribute['value'] ?? '');
        }
        ksort($attributes);
        $sorted = array();
        foreach ( $attributes as $name => $value ) {
            $sorted[] = array( 'name' => $name, 'value' => $value );
        }

        return $sorted;
    }

    private function safeIconSvg(string $value): string
    {
        $value = trim($value);
        if ( 1 !== preg_match('/^<svg[\s>]/i', $value) || 1 === preg_match('/(?:<\/?(?:script|style|foreignobject|iframe|object|embed|link)\b|\son[a-z]+\s*=|javascript\s*:)/i', $value) ) {
            return '';
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array( 'name' => self::LOCAL_NAME, 'block_json' => $this->blockJson($namespace), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ), 'assets' => $this->assets($namespace) );
    }
}
