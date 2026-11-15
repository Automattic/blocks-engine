<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * The front end applies some resting state that the block editor's own edit
 * components never render, so the editor canvas paints content the visitor
 * never sees:
 *  - a block whose source element is `hidden` keeps that attribute in
 *    `metadata.blocksEngineRuntimeAttributes`, applied by a render filter;
 *  - a captured dialog Group renders as a closed native `<dialog>`;
 *  - core/image renders its link without the saved anchor class
 *    (`linkClass`), so author rules keyed on that class miss the image, and
 *    writes an inline `height:auto` the saved image does not carry, which
 *    overrides the author's image height.
 *
 * The editor module restates that resting state on the editor's block
 * elements. A hidden block shows again while it or a descendant is selected,
 * so its content stays editable from the List View.
 */
final class EditorRestingStateRuntime
{
    /** Markers in saved markup that make the module apply. */
    public const MARKERS = array('blocksEngineRuntimeAttributes', 'blocksEngineDialog', '<!-- wp:image');

    public static function source(): string
    {
        return "add_action( 'enqueue_block_editor_assets', static function (): void { wp_add_inline_script( 'wp-block-editor', " . var_export(self::script(), true) . ", 'after' ); } );";
    }

    public static function script(): string
    {
        return <<<'JS'
( function( hooks, element, data, compose ) {
    if ( ! hooks || ! element || ! data || ! compose ) return;
    var createElement = element.createElement;
    function hiddenAtRest( attributes ) {
        var metadata = attributes && attributes.metadata || {};
        var runtime = metadata.blocksEngineRuntimeAttributes;
        var dialog = metadata.blocksEngineDialog;
        if ( runtime && 'string' === typeof runtime.hidden ) return true;
        return !! ( dialog && 'dialog' === dialog.tagName && ! dialog.adopt && ! ( dialog.attributes && 'open' in dialog.attributes ) );
    }
    function blockElement( clientId ) {
        var id = 'block-' + clientId;
        var found = document.getElementById( id );
        document.querySelectorAll( 'iframe[name="editor-canvas"]' ).forEach( function( frame ) {
            if ( ! found && frame.contentDocument ) found = frame.contentDocument.getElementById( id );
        } );
        return found;
    }
    hooks.addFilter( 'editor.BlockListBlock', 'blocks-engine/editor-resting-state', compose.createHigherOrderComponent( function( BlockListBlock ) {
        return function( props ) {
            var attributes = props.attributes || {};
            var hidden = hiddenAtRest( attributes );
            var editing = data.useSelect( function( select ) {
                var store = select( 'core/block-editor' );
                return hidden && ( store.isBlockSelected( props.clientId ) || store.hasSelectedInnerBlock( props.clientId, true ) );
            }, [ hidden, props.clientId ] );
            var image = 'core/image' === props.name;
            var linkClasses = image && attributes.href ? String( attributes.linkClass || '' ).split( /\s+/ ).filter( Boolean ) : [];
            var autoHeight = image && ( undefined === attributes.height || null === attributes.height || '' === attributes.height );
            element.useEffect( function() {
                if ( ! linkClasses.length && ! autoHeight ) return;
                var node = blockElement( props.clientId );
                if ( ! node ) return;
                var apply = function() {
                    var link = linkClasses.length ? node.querySelector( 'a[href]' ) : null;
                    if ( link ) linkClasses.forEach( function( name ) { if ( ! link.classList.contains( name ) ) link.classList.add( name ); } );
                    var img = autoHeight ? node.querySelector( 'img' ) : null;
                    if ( img && 'auto' === img.style.height ) img.style.removeProperty( 'height' );
                };
                apply();
                var observer = new node.ownerDocument.defaultView.MutationObserver( apply );
                observer.observe( node, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style' ] } );
                return function() { observer.disconnect(); };
            } );
            if ( ! hidden || editing ) return createElement( BlockListBlock, props );
            return createElement( BlockListBlock, Object.assign( {}, props, { wrapperProps: Object.assign( {}, props.wrapperProps, { hidden: true } ) } ) );
        };
    }, 'withBlocksEngineRestingState' ) );
} )( window.wp && window.wp.hooks, window.wp && window.wp.element, window.wp && window.wp.data, window.wp && window.wp.compose );
JS;
    }
}
