<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds a bounded List View carrier for exact source wrapper chains. */
final class LayoutShellBlockGenerator
{
    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    var useBlockProps = blockEditor.useBlockProps;
    function reactStyle( value ) {
        var style = {};
        var declaration = '';
        var depth = 0;
        var quote = '';
        function appendDeclaration( source ) {
            var separator = -1;
            var innerDepth = 0;
            var innerQuote = '';
            for ( var index = 0; index < source.length; index++ ) {
                var character = source.charAt( index );
                if ( innerQuote ) {
                    if ( character === '\\' ) { index++; }
                    else if ( character === innerQuote ) { innerQuote = ''; }
                    continue;
                }
                if ( character === '"' || character === "'" ) { innerQuote = character; }
                else if ( character === '(' ) { innerDepth++; }
                else if ( character === ')' && innerDepth ) { innerDepth--; }
                else if ( character === ':' && ! innerDepth ) { separator = index; break; }
            }
            if ( separator < 1 ) { return; }
            var property = source.slice( 0, separator ).trim();
            var propertyValue = source.slice( separator + 1 ).trim();
            if ( ! property || ! propertyValue ) { return; }
            var key = property.indexOf( '--' ) === 0 ? property : property.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } );
            style[ key ] = propertyValue;
        }
        value = value || '';
        for ( var index = 0; index < value.length; index++ ) {
            var character = value.charAt( index );
            if ( quote ) {
                declaration += character;
                if ( character === '\\' && index + 1 < value.length ) { declaration += value.charAt( ++index ); }
                else if ( character === quote ) { quote = ''; }
                continue;
            }
            if ( character === '"' || character === "'" ) { quote = character; declaration += character; }
            else if ( character === '(' ) { depth++; declaration += character; }
            else if ( character === ')' && depth ) { depth--; declaration += character; }
            else if ( character === ';' && ! depth ) { appendDeclaration( declaration ); declaration = ''; }
            else { declaration += character; }
        }
        appendDeclaration( declaration );
        return style;
    }
    function wrapperProps( attributes ) {
        var props = {};
        Object.keys( attributes || {} ).forEach( function( name ) {
            var value = attributes[ name ];
            if ( name === 'class' ) { props.className = value; }
            else if ( name === 'style' ) { props.style = reactStyle( value ); }
            else if ( name === 'tabindex' ) { props.tabIndex = value; }
            else { props[ name ] = value; }
        } );
        return props;
    }
    function wrappedContent( wrappers, content, outerProps ) {
        for ( var index = ( wrappers || [] ).length - 1; index >= 0; index-- ) {
            var props = wrapperProps( wrappers[ index ].attributes );
            if ( index === 0 && outerProps ) { props = outerProps( props ); }
            content = createElement( wrappers[ index ].tagName || 'div', props, content );
        }
        return content;
    }
    function readableName( value ) {
        value = String( value || '' ).trim();
        if ( ! value || value.indexOf( 'blocks-engine-' ) === 0 || value.indexOf( 'be-inline-' ) === 0 || value.indexOf( 'comp-' ) === 0 || /^[a-f0-9]{16,}$/.test( value ) || /^[a-z]{1,4}\d[a-z0-9_]*$/i.test( value ) || /^[A-Z][a-z][A-Z][a-z]{2,}$/.test( value ) ) { return ''; }
        value = value.replace( /^_+/, '' ).replace( /_[a-z0-9]{5,}_\d+$/i, '' );
        value = value.replace( /([a-z])([A-Z])/g, '$1 $2' ).replace( /[-_]+/g, ' ' ).replace( /\s+/g, ' ' ).trim();
        if ( ! value || 40 < value.length ) { return ''; }
        return value.replace( /\b\w/g, function( letter ) { return letter.toUpperCase(); } );
    }
    function shellLabel( wrappers ) {
        var semanticTags = { header: 'Header', nav: 'Navigation', main: 'Main', section: 'Section', article: 'Article', aside: 'Aside', footer: 'Footer' };
        var genericClasses = { container: true, root: true, section: true, responsive: true, background: true, item: true, undefined: true, 'builder-root': true, 'wp-block-group': true };
        var semantic = '';
        var detail = '';
        var component = false;
        ( wrappers || [] ).forEach( function( wrapper ) {
            var tagName = String( wrapper.tagName || 'div' ).toLowerCase();
            var attributes = wrapper.attributes || {};
            if ( ! semantic && semanticTags[ tagName ] ) { semantic = semanticTags[ tagName ]; }
            if ( ! detail ) { detail = readableName( attributes.id ); }
            if ( ! detail ) {
                var classNames = String( attributes.class || '' ).split( /\s+/ );
                if ( -1 !== classNames.indexOf( 'builder-root' ) ) { component = true; }
                classNames.some( function( className ) {
                    if ( genericClasses[ className ] ) { return false; }
                    if ( /^[A-Za-z]+$/.test( className ) && /[a-z][A-Z]/.test( className ) ) { return false; }
                    var candidate = readableName( className );
                    if ( candidate === 'Root' || candidate === 'Internal Container Root' ) { return false; }
                    detail = candidate;
                    return !! detail;
                } );
            }
        } );
        if ( semantic && detail && semantic.toLowerCase() !== detail.toLowerCase() ) { return semantic + ': ' + detail; }
        if ( semantic ) { return semantic; }
        if ( detail ) { return 'Layout: ' + detail; }
        if ( component ) { return 'Component container'; }
        return 'Layout shell (' + ( wrappers || [] ).length + ' wrappers)';
    }
    function savedContent( wrappers ) { return wrappedContent( wrappers, createElement( InnerBlocks.Content ) ); }
    function edit( props ) {
        var wrappers = props.attributes.wrappers || [];
        // This marker identifies the one Gutenberg-owned layer inside the
        // authored wrapper chain; nested native blocks must retain their own
        // editor topology.
        var content = createElement( InnerBlocks, { className: 'blocks-engine-layout-shell-editor-inner-blocks' } );
        if ( wrappers.length ) { content = wrappedContent( wrappers, content ); }
        return createElement( 'div', useBlockProps( { style: { display: 'contents' } } ), content );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { wrappers: { type: 'array', default: [] } },
        supports: { html: false, reusable: false, renaming: false },
        __experimentalLabel: function( attributes, options ) {
            var context = options && options.context;
            return context === 'list-view' || context === 'breadcrumb' ? shellLabel( attributes.wrappers ) : null;
        },
        edit: edit,
        save: function( props ) { return savedContent( props.attributes.wrappers ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        return array(
            'name' => 'layout-shell',
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Layout Shell',
                'category' => 'design',
                'editorScript' => 'file:./index.js',
                'attributes' => array('wrappers' => array('type' => 'array', 'default' => array())),
                'supports' => array('html' => false, 'reusable' => false, 'renaming' => false),
            ),
            'assets' => array('index.js' => str_replace('__BLOCK_NAME__', $blockName, $script)),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }
}
