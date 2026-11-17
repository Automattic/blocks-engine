<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Shared wrapper-free editor surface for captured HTML companions. */
final class RawHtmlEditorRoot
{
    public static function script(string $script): string
    {
        $helper = <<<'JS'
    function rawHtmlRoot( content, blockProps ) {
        var template = document.createElement( 'template' );
        template.innerHTML = content;
        // The safe frontend renderer lowers autonomous custom tags to divs.
        Array.from( template.content.querySelectorAll( '*' ) ).forEach( function( node ) {
            if ( node.localName.indexOf( '-' ) === -1 ) return;
            var replacement = document.createElement( 'div' );
            Array.from( node.attributes ).forEach( function( attribute ) { replacement.setAttribute( attribute.name, attribute.value ); } );
            replacement.append.apply( replacement, Array.from( node.childNodes ) );
            node.replaceWith( replacement );
        } );
        var roots = Array.from( template.content.childNodes ).filter( function( node ) { return node.nodeType !== 3 || node.textContent.trim(); } );
        if ( ! roots.length ) return createElement( 'div', blockProps );
        return createElement( element.Fragment, null, roots.map( function( root, index ) {
            if ( root.nodeType !== 1 ) return root.textContent;
            var attributes = {};
            // Retain captured HTML attributes for display; React may warn about raw
            // names and intentionally ignores inline on* handlers in the editor.
            Array.from( root.attributes ).forEach( function( attribute ) {
                if ( attribute.name !== 'style' && attribute.name !== 'class' ) attributes[ attribute.name ] = attribute.value;
            } );
            var style = {};
            Array.from( root.style ).forEach( function( name ) {
                var key = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( match, letter ) { return letter.toUpperCase(); } );
                style[ key ] = root.style.getPropertyValue( name );
            } );
            // Captured siblings intentionally stay wrapper-free; only the first root owns selection.
            var props = index === 0 ? blockProps : {};
            var rootProps = Object.assign( {}, attributes, props, {
                key: index,
                className: Array.from( new Set( ( ( root.className || '' ) + ' ' + ( props.className || '' ) ).split( /\s+/ ).filter( Boolean ) ) ).join( ' ' ),
                style: Object.assign( {}, props.style, style )
            } );
            // React forbids inner HTML on void elements, including an empty string.
            if ( ! /^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/.test( root.localName ) ) {
                rootProps.dangerouslySetInnerHTML = { __html: root.innerHTML };
            }
            var priorities = Array.from( root.style ).filter( function( name ) { return root.style.getPropertyPriority( name ); } );
            if ( priorities.length ) {
                // React's style object cannot express !important. Apply priorities after mounting,
                // while forwarding Gutenberg's ref so selection and toolbar anchoring still work.
                rootProps.ref = function( node ) {
                    if ( node ) priorities.forEach( function( name ) { node.style.setProperty( name, root.style.getPropertyValue( name ), root.style.getPropertyPriority( name ) ); } );
                    if ( typeof props.ref === 'function' ) return props.ref( node );
                    if ( props.ref ) props.ref.current = node;
                };
            }
            return createElement( root.localName, rootProps );
        } ) );
    }
JS;
        return str_replace('    var createElement = element.createElement;', "    var createElement = element.createElement;\n" . $helper, $script);
    }
}
