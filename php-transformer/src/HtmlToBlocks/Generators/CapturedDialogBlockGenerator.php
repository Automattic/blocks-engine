<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Defines the editable companion block used for captured dialogs. */
final class CapturedDialogBlockGenerator
{
    public const LOCAL_NAME = 'captured-dialog';

    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $attributes = array(
            'dialogId' => array('type' => 'string', 'default' => ''),
            'triggerIds' => array('type' => 'array', 'default' => array(), 'items' => array('type' => 'string')),
            'ariaLabel' => array('type' => 'string', 'default' => ''),
            'ariaLabelledby' => array('type' => 'string', 'default' => ''),
            'ariaDescribedby' => array('type' => 'string', 'default' => ''),
            'className' => array('type' => 'string', 'default' => ''),
            'addCloseButton' => array('type' => 'boolean', 'default' => false),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    function dialogProps( attrs ) {
        return { id: attrs.dialogId || undefined, className: attrs.className || undefined, 'aria-label': attrs.ariaLabel || undefined, 'aria-labelledby': attrs.ariaLabelledby || undefined, 'aria-describedby': attrs.ariaDescribedby || undefined, 'data-blocks-engine-triggers': ( attrs.triggerIds || [] ).join( ' ' ) || undefined };
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false },
        edit: function( props ) { return createElement( 'div', { className: 'blocks-engine-captured-dialog-editor' }, createElement( 'strong', null, props.attributes.ariaLabel || 'Dialog' ), createElement( InnerBlocks ) ); },
        save: function( props ) { return createElement( 'dialog', dialogProps( props.attributes ), props.attributes.addCloseButton ? createElement( 'button', { type: 'button', 'data-blocks-engine-dialog-close': 'true', 'aria-label': 'Close' }, 'Close' ) : null, createElement( InnerBlocks.Content ) ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        $view = <<<'JS'
( function() {
    function mount( dialog ) {
        if ( dialog.dataset.blocksEngineMounted ) return;
        var triggers = ( dialog.getAttribute( 'data-blocks-engine-triggers' ) || '' ).split( /\s+/ ).map( function( id ) { return document.getElementById( id ); } ).filter( Boolean );
        if ( ! triggers.length ) return;
        dialog.dataset.blocksEngineMounted = 'true';
        triggers.forEach( function( trigger ) { trigger.addEventListener( 'click', function( event ) { event.preventDefault(); if ( dialog.showModal ) dialog.showModal(); else dialog.setAttribute( 'open', '' ); } ); } );
        dialog.addEventListener( 'click', function( event ) { if ( event.target === dialog || event.target.closest( '[data-blocks-engine-dialog-close]' ) ) dialog.close ? dialog.close() : dialog.removeAttribute( 'open' ); } );
    }
    function mountAll() { document.querySelectorAll( 'dialog[data-blocks-engine-triggers]' ).forEach( mount ); }
    if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
JS;

        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Dialog',
                'category' => 'widgets',
                'description' => 'Editable dialog content opened by a page control.',
                'editorScript' => 'file:./index.js',
                'viewScript' => 'file:./view.js',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false),
            ),
            'assets' => array(
                'index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__'), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $editor),
            ),
            'view_js' => $view,
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }
}
