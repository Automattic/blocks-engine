<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Builds the bounded author-facing editor module for declared responsive
 * counterparts.
 *
 * The module is intentionally conservative:
 *  - it only renders a control for block types carrying a declared
 *    counterpart token class with a compatible content attribute;
 *  - it requires exactly one other block in the editor carrying the same
 *    token, resolved under the declared responsive variant roots;
 *  - it mirrors only the compatible content attribute while the paired block
 *    is selected, with a visible per-selection opt-out and manual copy action.
 */
final class ResponsiveCounterpartEditorModule
{
    public const HANDLE = 'blocks-engine-responsive-counterparts';

    /** @return array<string, mixed> */
    public function module(): array
    {
        return array(
            'handle' => self::HANDLE,
            'script_dependencies' => array('wp-hooks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-notices'),
            'content' => $this->script(),
        );
    }

    private function script(): string
    {
        return <<<'JS'
( function( hooks, element, components, blockEditor, data ) {
    var createElement = element.createElement;
    var Fragment = element.Fragment;
    var useState = element.useState;
    var TOKEN_CLASS = /^be-responsive-counterpart-([a-f0-9]{12})$/;
    var VARIANT_CLASS = /^site-document-variant-([a-z][a-z0-9_-]{0,31})$/;
    var CONTENT_ATTRIBUTES = { 'core/paragraph': 'content', 'core/heading': 'content', 'core/button': 'text' };
    var VARIANT_LABELS = { default: 'default (desktop)' };

    function classes( value ) {
        return String( value || '' ).trim().split( /\s+/ ).filter( Boolean );
    }
    function tokenFrom( value ) {
        var found = '';
        classes( value ).some( function( name ) { var match = TOKEN_CLASS.exec( name ); if ( match ) { found = match[ 1 ]; return true; } return false; } );
        return found;
    }
    function variantLabelFrom( value ) {
        var found = '';
        classes( value ).some( function( name ) { var match = VARIANT_CLASS.exec( name ); if ( match ) { found = match[ 1 ]; return true; } return false; } );
        return found;
    }
    function listBlocks() {
        var store = data.select( 'core/block-editor' );
        var roots = store.getBlocks ? store.getBlocks() : [];
        var all = [];
        function walk( blocks ) {
            ( blocks || [] ).forEach( function( block ) {
                all.push( block );
                if ( block.innerBlocks ) { walk( block.innerBlocks ); }
            } );
        }
        walk( roots );
        return all;
    }
    function variantOf( block ) {
        var store = data.select( 'core/block-editor' );
        var parents = store.getBlockParents ? store.getBlockParents( block.clientId ) : [];
        var label = variantLabelFrom( block.attributes && block.attributes.className );
        if ( label ) { return label; }
        for ( var index = parents.length - 1; 0 <= index; index-- ) {
            var parent = store.getBlock ? store.getBlock( parents[ index ] ) : null;
            label = parent ? variantLabelFrom( parent.attributes && parent.attributes.className ) : '';
            if ( label ) { return label; }
        }
        return '';
    }
    function counterpartFor( clientId, token ) {
        var matches = listBlocks().filter( function( block ) {
            return block.clientId !== clientId && tokenFrom( block.attributes && block.attributes.className ) === token;
        } );
        if ( 1 !== matches.length ) { return null; }
        var variant = variantOf( matches[ 0 ] );
        return variant ? { block: matches[ 0 ], variant: variant } : null;
    }
    function applyToCounterpart( props, counterpart, attribute, notify ) {
        var value = props.attributes[ attribute ];
        var next = {};
        next[ attribute ] = value;
        data.dispatch( 'core/block-editor' ).updateBlockAttributes( counterpart.block.clientId, next );
        if ( notify ) {
            data.dispatch( 'core/notices' ).createNotice( 'success', 'Responsive counterpart: applied ' + attribute + ' to the ' + ( VARIANT_LABELS[ counterpart.variant ] || counterpart.variant ) + ' block.', { type: 'snackbar', isDismissible: true } );
        }
    }
    hooks.addFilter( 'editor.BlockEdit', 'blocks-engine/responsive-counterparts', function( BlockEdit ) {
        return function( props ) {
            var token = tokenFrom( props.attributes && props.attributes.className );
            var attribute = CONTENT_ATTRIBUTES[ props.name ] || '';
            var counterpart = token && attribute ? counterpartFor( props.clientId, token ) : null;
            var edited = createElement( BlockEdit, props );
            var mirrorState = useState( true );
            var isMirroring = mirrorState[ 0 ];
            var setMirroring = mirrorState[ 1 ];
            if ( ! counterpart ) { return edited; }
            var variantLabel = VARIANT_LABELS[ counterpart.variant ] || counterpart.variant;
            var mirroredProps = Object.assign( {}, props, {
                setAttributes: function( nextAttributes ) {
                    props.setAttributes( nextAttributes );
                    if ( isMirroring && Object.prototype.hasOwnProperty.call( nextAttributes, attribute ) ) {
                        applyToCounterpart( { attributes: Object.assign( {}, props.attributes, nextAttributes ) }, counterpart, attribute, false );
                    }
                }
            } );
            edited = createElement( BlockEdit, mirroredProps );
            return createElement( Fragment, null,
                edited,
                createElement( blockEditor.InspectorControls, null,
                    createElement( components.PanelBody, { title: 'Responsive counterpart', initialOpen: true },
                        createElement( 'p', { className: 'blocks-engine-responsive-counterpart-note' },
                            'Declared ' + variantLabel + ' counterpart for this ' + ( 'text' === attribute ? 'text' : 'link' ) + ' block.' ),
                        createElement( components.ToggleControl, {
                            label: 'Mirror changes to ' + variantLabel,
                            checked: isMirroring,
                            onChange: setMirroring,
                            help: isMirroring ? 'Changes to this content are mirrored while this block is selected.' : 'Changes remain independent until mirroring is enabled.'
                        } ),
                        createElement( components.Button, {
                            variant: 'secondary',
                            className: 'blocks-engine-responsive-counterpart-apply',
                            onClick: function() { applyToCounterpart( props, counterpart, attribute, true ); }
                        }, 'Copy ' + attribute + ' to ' + variantLabel )
                    )
                )
            );
        };
    } );
} )( window.wp.hooks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.data );
JS;
    }
}
