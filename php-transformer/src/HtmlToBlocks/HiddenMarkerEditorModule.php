<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Hidden source-state carriers are not author-facing RichText fields. */
final class HiddenMarkerEditorModule
{
    /** @return array<string, mixed> */
    public function module(): array
    {
        return array(
            'handle' => 'blocks-engine-hidden-markers',
            'dependencies' => array('wp-hooks', 'wp-element'),
            'content' => <<<'JS'
( function( hooks, element ) {
    hooks.addFilter( 'editor.BlockEdit', 'blocks-engine/hidden-markers', function( BlockEdit ) {
        return function( props ) {
            var classes = String( props.attributes && props.attributes.className || '' ).split( /\s+/ );
            // Disabling block editing still mounts RichText with a hidden
            // contenteditable root. Do not mount the edit view at all, including
            // InnerBlocks of hidden groups. This leaves the saved block intact.
            if ( classes.indexOf( 'blocks-engine-hidden-richtext-marker' ) !== -1 ) {
                return null;
            }
            return element.createElement( BlockEdit, props );
        };
    } );
} )( window.wp.hooks, window.wp.element );
JS,
        );
    }
}
