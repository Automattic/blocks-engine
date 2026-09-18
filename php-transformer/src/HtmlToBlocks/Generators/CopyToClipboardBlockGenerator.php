<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/**
 * Builds an editable companion block that copies authored text to the clipboard.
 */
final class CopyToClipboardBlockGenerator
{
    public const LOCAL_NAME = 'copy-to-clipboard';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Copy to Clipboard',
            'category' => 'widgets',
            'description' => 'An editable control that copies authored text to the clipboard.',
            'editorScript' => 'file:./index.js',
            'viewScript' => 'file:./view.js',
            'style' => 'file:./style.css',
            'attributes' => array(
                'id' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'label' => array( 'type' => 'string', 'default' => '' ),
                'copyText' => array( 'type' => 'string', 'default' => '' ),
                'iconHtml' => array( 'type' => 'string', 'default' => '' ),
                'copiedAnnouncement' => array( 'type' => 'string', 'default' => 'Copied' ),
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
    var TextareaControl = components.TextareaControl;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function styleObject( value ) { if ( ! value ) return undefined; return String( value ).split( ';' ).reduce( function( output, declaration ) { var separator = declaration.indexOf( ':' ); if ( separator < 1 ) return output; var name = declaration.slice( 0, separator ).trim(); var property = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } ); output[ property ] = declaration.slice( separator + 1 ).trim(); return output; }, {} ); }
    function safeIcon( value ) { value = String( value || '' ).trim(); if ( ! value ) return ''; if ( /^<svg(?:\s|>)/i.test( value ) ) return /(?:<\/?(?:script|style|foreignobject|iframe|object|embed|link)\b|\son[a-z]+\s*=|javascript\s*:)/i.test( value ) ? '' : value; if ( /^<img\b[^>]*\/?>$/i.test( value ) && ! /(?:\son[a-z]+\s*=|javascript\s*:)/i.test( value ) ) return value; return ''; }
    function accessibleName( attrs ) { return attrs.ariaLabel || attrs.label || 'Copy'; }
    function markup( attrs ) { var output = '<button type="button"'; [ 'id', 'ariaLabel', 'className', 'style' ].forEach( function( key ) { var value = 'ariaLabel' === key ? accessibleName( attrs ) : attrs[ key ]; if ( value ) output += ' ' + ( 'className' === key ? 'class' : ( 'ariaLabel' === key ? 'aria-label' : key ) ) + '="' + escapeAttribute( value ) + '"'; } ); output += ' data-blocks-engine-copy="true" data-blocks-engine-copy-text="' + escapeAttribute( attrs.copyText ) + '" data-blocks-engine-copy-announcement="' + escapeAttribute( attrs.copiedAnnouncement || 'Copied' ) + '">'; var icon = safeIcon( attrs.iconHtml ); var label = escapeAttribute( attrs.label ); output += icon + ( icon && label ? ' ' : '' ) + label + '<span class="blocks-engine-copy-to-clipboard__status" aria-live="polite"></span></button>'; return output; }
    function edit( props ) { var attrs = props.attributes; var icon = safeIcon( attrs.iconHtml ); var children = []; if ( icon ) children.push( createElement( element.RawHTML, { key: 'icon' }, icon ) ); if ( icon && attrs.label ) children.push( ' ' ); children.push( attrs.label || '' ); var button = createElement( 'button', { type: 'button', id: attrs.id || undefined, 'aria-label': accessibleName( attrs ), className: attrs.className || undefined, style: styleObject( attrs.style ) }, children ); return createElement( element.Fragment, null, createElement( InspectorControls, null, createElement( PanelBody, { title: 'Copy settings' }, createElement( TextControl, { label: 'Label', value: attrs.label || '', onChange: function( label ) { props.setAttributes( { label: label } ); } } ), createElement( TextareaControl, { label: 'Copied text', value: attrs.copyText || '', onChange: function( copyText ) { props.setAttributes( { copyText: copyText } ); } } ), createElement( TextControl, { label: 'Accessible name', value: attrs.ariaLabel || '', onChange: function( ariaLabel ) { props.setAttributes( { ariaLabel: ariaLabel } ); } } ) ) ), button ); }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        $style = '.blocks-engine-copy-to-clipboard__status{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}';

        return array(
            'index.js' => str_replace(array( '__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__' ), array( $namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ), $script),
            'style.css' => $style,
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $markup = '<button type="button"';
        $ariaLabel = (string) ($attrs['ariaLabel'] ?? '');
        $label = (string) ($attrs['label'] ?? '');
        if ( '' === $ariaLabel ) {
            $ariaLabel = '' !== $label ? $label : 'Copy';
        }
        foreach ( array( 'id' => (string) ($attrs['id'] ?? ''), 'ariaLabel' => $ariaLabel, 'className' => (string) ($attrs['className'] ?? ''), 'style' => (string) ($attrs['style'] ?? '') ) as $key => $value ) {
            if ( '' !== $value ) {
                $markup .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : $key ) ) . '="' . $escape($value) . '"';
            }
        }
        $announcement = (string) ($attrs['copiedAnnouncement'] ?? '');
        if ( '' === $announcement ) {
            $announcement = 'Copied';
        }
        $icon = $this->safeIcon((string) ($attrs['iconHtml'] ?? ''));
        $escapedLabel = $escape($label);

        return $markup
            . ' data-blocks-engine-copy="true"'
            . ' data-blocks-engine-copy-text="' . $escape($attrs['copyText'] ?? '') . '"'
            . ' data-blocks-engine-copy-announcement="' . $escape($announcement) . '">'
            . $icon
            . ( '' !== $icon && '' !== $escapedLabel ? ' ' : '' )
            . $escapedLabel
            . '<span class="blocks-engine-copy-to-clipboard__status" aria-live="polite"></span></button>';
    }

    public function safeIcon(string $html): string
    {
        $html = trim($html);
        if ( '' === $html ) {
            return '';
        }
        if ( 1 === preg_match('/^<svg[\s>]/i', $html) ) {
            return SourceDom::isSafeSvgContent($html) && ! preg_match('/<\/?(?:script|style|foreignobject|iframe|object|embed|link)\b|\son[a-z]+\s*=|javascript\s*:/i', $html) ? $html : '';
        }
        if ( 1 === preg_match('/^<img\b[^>]*\/?>$/i', $html) && ! preg_match('/\son[a-z]+\s*=|javascript\s*:/i', $html) ) {
            return $html;
        }

        return '';
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        $view = <<<'JS'
( function() {
    function announce( button, message ) {
        var status = button.querySelector( '.blocks-engine-copy-to-clipboard__status' );
        if ( status ) {
            status.textContent = message;
        }
    }
    function copyText( text ) {
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            return navigator.clipboard.writeText( text );
        }
        return new Promise( function( resolve, reject ) {
            var input = document.createElement( 'textarea' );
            input.value = text;
            input.setAttribute( 'readonly', '' );
            input.style.position = 'fixed';
            input.style.left = '-9999px';
            document.body.appendChild( input );
            input.select();
            try {
                document.execCommand( 'copy' ) ? resolve() : reject( new Error( 'copy failed' ) );
            } catch ( error ) {
                reject( error );
            }
            document.body.removeChild( input );
        } );
    }
    function mount( button ) {
        if ( button.dataset.blocksEngineCopyMounted ) {
            return;
        }
        button.dataset.blocksEngineCopyMounted = 'true';
        button.addEventListener( 'click', function( event ) {
            event.preventDefault();
            var text = button.getAttribute( 'data-blocks-engine-copy-text' ) || '';
            var announcement = button.getAttribute( 'data-blocks-engine-copy-announcement' ) || 'Copied';
            copyText( text ).then( function() {
                announce( button, announcement );
                window.setTimeout( function() { announce( button, '' ); }, 2000 );
            } );
        } );
    }
    function mountAll() {
        document.querySelectorAll( '[data-blocks-engine-copy="true"]' ).forEach( mount );
    }
    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', mountAll );
    } else {
        mountAll();
    }
} )();
JS;

        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ),
            'assets' => $this->assets($namespace),
            'view_js' => $view,
        );
    }
}
