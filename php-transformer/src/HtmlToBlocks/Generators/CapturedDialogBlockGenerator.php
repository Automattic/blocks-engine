<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

/** Defines the editable companion block used for captured dialogs. */
final class CapturedDialogBlockGenerator
{
    public const LOCAL_NAME = 'captured-dialog';

    /**
     * Ancestor attributes a captured-dialog binding may change: the attribute
     * set the capture records and its disclosure runtime replays (`class`,
     * `style`, `hidden`, `aria-*`, `data-*`). Event handlers and URL-bearing
     * attributes are never recorded, so they are never replayed. Blocks
     * Engine's own `data-blocks-engine-*` namespace is excluded: it carries the
     * native dialog's own state and capture evidence, never a source binding.
     * Shared by the projector, the converter and the view script.
     */
    public const ANCESTOR_ATTRIBUTE_PATTERN = '^(?:class|style|hidden|aria-[a-z0-9-]+|data-(?!blocks-engine-)[a-z0-9_.:-]+)$';
    private const MAX_ANCESTOR_ROWS = 8;
    private const MAX_ANCESTOR_ATTRIBUTES = 32;
    private const MAX_ANCESTOR_VALUE_BYTES = 2048;

    /**
     * The replayable rows of a captured ancestor binding. A row keeps its tag
     * and its closed/opened values for the same allowed attributes. A row with
     * any other attribute is dropped; a malformed binding yields no rows.
     *
     * @return array<int, array{tag:string, closed:array<string, string|null>, opened:array<string, string|null>}>
     */
    public static function ancestorState(mixed $bindings): array
    {
        if (! is_array($bindings) || ! array_is_list($bindings) || count($bindings) > self::MAX_ANCESTOR_ROWS) return array();
        $state = array();
        foreach ($bindings as $binding) {
            $tag = is_array($binding) ? strtolower((string) ($binding['tag'] ?? '')) : '';
            if (1 !== preg_match('/^[a-z][a-z0-9-]{0,31}$/', $tag) || ! is_array($binding['closed'] ?? null) || ! is_array($binding['opened'] ?? null)) return array();
            $sides = array();
            foreach (array('closed', 'opened') as $side) {
                if (count($binding[$side]) > self::MAX_ANCESTOR_ATTRIBUTES) continue 2;
                foreach ($binding[$side] as $name => $value) {
                    if (! is_string($name) || 1 !== preg_match('/' . self::ANCESTOR_ATTRIBUTE_PATTERN . '/', $name) || (null !== $value && ! is_string($value)) || strlen((string) $value) > self::MAX_ANCESTOR_VALUE_BYTES) continue 3;
                    $sides[$side][$name] = $value;
                }
            }
            if (array_keys($sides['closed'] ?? array()) !== array_keys($sides['opened'] ?? array()) || array() === ($sides['closed'] ?? array())) return array();
            $state[] = array('tag' => $tag, 'closed' => $sides['closed'], 'opened' => $sides['opened']);
        }
        return $state;
    }

    /** @return array<string, mixed> */
    public function definition(string $blockName): array
    {
        $attributes = array(
            'tagName' => array('type' => 'string', 'default' => 'dialog'),
            'sourceStyle' => array('type' => 'string', 'default' => ''),
            'ancestorUnverified' => array('type' => 'string', 'default' => ''),
            'dialogId' => array('type' => 'string', 'default' => ''),
            'triggerIds' => array('type' => 'array', 'default' => array(), 'items' => array('type' => 'string')),
            'ariaLabel' => array('type' => 'string', 'default' => ''),
            'ariaLabelledby' => array('type' => 'string', 'default' => ''),
            'ariaDescribedby' => array('type' => 'string', 'default' => ''),
            'className' => array('type' => 'string', 'default' => ''),
            'presentation' => array('type' => 'string', 'default' => ''),
            'placement' => array('type' => 'string', 'default' => ''),
            'addCloseButton' => array('type' => 'boolean', 'default' => false),
            'gallerySelection' => array('type' => 'array', 'default' => array()),
            'ancestorState' => array('type' => 'array', 'default' => array()),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    function styleObject( value ) { if ( ! value ) return undefined; return String( value ).split( ';' ).reduce( function( output, declaration ) { var separator = declaration.indexOf( ':' ); if ( separator < 1 ) return output; var name = declaration.slice( 0, separator ).trim(); var property = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } ); output[ property ] = declaration.slice( separator + 1 ).trim(); return output; }, {} ); }
    function dialogProps( attrs ) {
        return { id: attrs.dialogId || undefined, className: attrs.className || undefined, style: styleObject( attrs.sourceStyle ), '__DIALOG_ANCESTOR_UNVERIFIED__': attrs.ancestorUnverified || undefined, 'data-blocks-engine-presentation': attrs.presentation || undefined, 'data-blocks-engine-placement': attrs.placement || undefined, 'aria-label': attrs.ariaLabel || undefined, 'aria-labelledby': attrs.ariaLabelledby || undefined, 'aria-describedby': attrs.ariaDescribedby || undefined, 'data-blocks-engine-triggers': ( attrs.triggerIds || [] ).join( ' ' ) || undefined, 'data-blocks-engine-gallery-selection': attrs.gallerySelection && attrs.gallerySelection.length ? JSON.stringify( attrs.gallerySelection ) : undefined, 'data-blocks-engine-ancestor-state': attrs.ancestorState && attrs.ancestorState.length ? JSON.stringify( attrs.ancestorState ) : undefined };
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false },
        edit: function( props ) { return createElement( 'div', { className: 'blocks-engine-captured-dialog-editor' }, createElement( 'strong', null, props.attributes.ariaLabel || 'Dialog' ), createElement( InnerBlocks ) ); },
        save: function( props ) { return createElement( props.attributes.tagName === 'div' && props.attributes.presentation === 'dropdown' ? 'div' : 'dialog', dialogProps( props.attributes ), props.attributes.addCloseButton ? createElement( 'button', { type: 'button', 'data-blocks-engine-dialog-close': 'true', 'aria-label': 'Close' }, 'Close' ) : null, createElement( InnerBlocks.Content ) ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        $view = <<<'JS'
( function() {
    // Replay the source-proven ancestor changes recorded for this control (for
    // example a header that paints itself only while its menu is open), as the
    // capture's disclosure runtime does.
    var ATTRIBUTE = new RegExp( '__ANCESTOR_ATTRIBUTE_PATTERN__' );
    function tokens( value ) { return ( value || '' ).split( /\s+/ ).filter( Boolean ); }
    // Attributes other than class and style are set to the recorded value, or
    // removed when the recorded value is null.
    function attributeNames( values ) { return Object.keys( values ).filter( function( name ) { return 'class' !== name && 'style' !== name && ATTRIBUTE.test( name ); } ); }
    function replayAttributes( node, to ) { attributeNames( to ).forEach( function( name ) { if ( null === to[ name ] ) node.removeAttribute( name ); else node.setAttribute( name, to[ name ] ); } ); }
    function declarations( value ) {
        var out = {};
        ( value || '' ).split( ';' ).forEach( function( part ) { var at = part.indexOf( ':' ); if ( at > 0 ) out[ part.slice( 0, at ).trim().toLowerCase() ] = part.slice( at + 1 ).trim(); } );
        return out;
    }
    // The ancestor is the nearest one with the recorded tag and closed class
    // tokens, preferring one whose other recorded attributes hold their closed
    // values (block markup can drop an attribute the capture recorded).
    function ancestorFor( trigger, binding ) {
        var wanted = tokens( binding.closed[ 'class' ] ), names = attributeNames( binding.closed ), fallback = null;
        for ( var node = trigger.parentElement; node; node = node.parentElement ) {
            if ( node.tagName.toLowerCase() !== binding.tag || ! wanted.every( function( token ) { return node.classList.contains( token ); } ) ) continue;
            if ( names.every( function( name ) { return node.getAttribute( name ) === binding.closed[ name ]; } ) ) return node;
            fallback = fallback || node;
        }
        return fallback;
    }
    function replay( node, from, to ) {
        if ( 'class' in to ) {
            tokens( from[ 'class' ] ).forEach( function( token ) { if ( tokens( to[ 'class' ] ).indexOf( token ) < 0 ) node.classList.remove( token ); } );
            tokens( to[ 'class' ] ).forEach( function( token ) { node.classList.add( token ); } );
            if ( null === to[ 'class' ] && ! node.classList.length ) node.removeAttribute( 'class' );
        }
        if ( 'style' in to ) {
            var before = declarations( from.style ), after = declarations( to.style );
            Object.keys( before ).forEach( function( name ) { if ( ! ( name in after ) ) node.style.removeProperty( name ); } );
            Object.keys( after ).forEach( function( name ) { var value = after[ name ].replace( /\s*!important$/i, '' ); node.style.setProperty( name, value, value === after[ name ] ? '' : 'important' ); } );
        }
        replayAttributes( node, to );
    }
    // Block styles can freeze the closed paint inline on the ancestor. Lift
    // only the inline declarations that a rule for a replayed open class sets,
    // so the recorded open state paints, and put them back on close.
    function maskedDeclarations( node, added ) {
        var names = {};
        function walk( rules ) {
            for ( var i = 0; i < rules.length; i++ ) {
                var rule = rules[ i ];
                if ( rule.media && ! window.matchMedia( rule.media.mediaText ).matches ) continue;
                if ( rule.cssRules && ! rule.selectorText ) { walk( rule.cssRules ); continue; }
                if ( ! rule.selectorText || ! rule.style || ! added.some( function( token ) { return rule.selectorText.indexOf( CSS.escape( token ) ) > -1; } ) ) continue;
                try { if ( ! node.matches( rule.selectorText ) ) continue; } catch ( error ) { continue; }
                // Longhands and the serialized shorthands both count: an inline
                // shorthand holding var() keeps its longhands unresolved.
                for ( var j = 0; j < rule.style.length; j++ ) names[ rule.style[ j ] ] = true;
                Object.keys( declarations( rule.style.cssText ) ).forEach( function( name ) { names[ name ] = true; } );
            }
        }
        Array.prototype.forEach.call( document.styleSheets, function( sheet ) { try { walk( sheet.cssRules ); } catch ( error ) {} } );
        var lifted = {};
        Object.keys( names ).forEach( function( name ) {
            if ( '' === node.style.getPropertyValue( name ) ) return;
            lifted[ name ] = [ node.style.getPropertyValue( name ), node.style.getPropertyPriority( name ) ];
            node.style.removeProperty( name );
        } );
        return lifted;
    }
    // Each trigger's ancestors, resolved once. Like the capture runtime on
    // load, every bound ancestor starts with its recorded closed attributes.
    function boundAncestors( dialog, trigger ) {
        var bound = JSON.parse( dialog.getAttribute( 'data-blocks-engine-ancestor-state' ) || '[]' ).map( function( binding ) { return { binding: binding, node: ancestorFor( trigger, binding ) }; } ).filter( function( entry ) { return entry.node; } );
        bound.forEach( function( entry ) { replayAttributes( entry.node, entry.binding.closed ); } );
        return bound;
    }
    function applyAncestors( dialog, bound ) {
        var applied = [];
        bound.forEach( function( entry ) {
            var node = entry.node, binding = entry.binding;
            replay( node, binding.closed, binding.opened );
            var added = tokens( binding.opened[ 'class' ] ).filter( function( token ) { return tokens( binding.closed[ 'class' ] ).indexOf( token ) < 0; } );
            applied.push( { node: node, binding: binding, lifted: added.length ? maskedDeclarations( node, added ) : {} } );
        } );
        dialog.addEventListener( 'close', function restore() {
            dialog.removeEventListener( 'close', restore );
            applied.forEach( function( entry ) {
                replay( entry.node, entry.binding.opened, entry.binding.closed );
                Object.keys( entry.lifted ).forEach( function( name ) { entry.node.style.setProperty( name, entry.lifted[ name ][ 0 ], entry.lifted[ name ][ 1 ] ); } );
            } );
        } );
    }
    // Read a property's settled value: a replayed class change may still be
    // transitioning, and the panel should take the paint it transitions to.
    function settled( node, property, cssProperty ) {
        var transition = node.getAnimations ? node.getAnimations().find( function( animation ) { return animation.transitionProperty === cssProperty; } ) : null;
        var frames = transition && transition.effect ? transition.effect.getKeyframes() : [];
        var last = frames.length ? frames[ frames.length - 1 ][ property ] : '';
        return last || window.getComputedStyle( node )[ property ];
    }
    // A dropdown with no observed source place drops under the trigger's
    // header. Its paint usually lives on that header, and it joins the
    // trigger's stacking context so the page's own layers stay beneath it.
    function placeDropdown( dialog, trigger ) {
        var host = trigger.closest( 'header,[role="banner"]' ) || trigger;
        var node = trigger;
        var background = '';
        var backdrop = 'none';
        var layer = 'auto';
        while ( node && node.nodeType === 1 ) {
            var paint = settled( node, 'backgroundColor', 'background-color' );
            if ( ! background && paint && 'transparent' !== paint && ! /rgba\(.*,\s*0\)$/.test( paint ) ) { background = paint; backdrop = settled( node, 'backdropFilter', 'backdrop-filter' ) || 'none'; }
            if ( 'auto' === layer && 'auto' !== window.getComputedStyle( node ).zIndex ) layer = window.getComputedStyle( node ).zIndex;
            node = node.parentElement;
        }
        dialog.style.setProperty( '--blocks-engine-dropdown-background', background || window.getComputedStyle( document.body ).backgroundColor );
        dialog.style.setProperty( '--blocks-engine-dropdown-backdrop', backdrop );
        dialog.style.setProperty( '--blocks-engine-dropdown-layer', layer );
        dialog.style.setProperty( '--blocks-engine-dropdown-top', Math.max( 0, Math.round( host.getBoundingClientRect().bottom ) ) + 'px' );
    }
    // The control a person operates: the trigger itself or the native button
    // or link a block wrapper holds.
    function control( trigger ) {
        return trigger.matches( 'button,a,[role="button"]' ) ? trigger : trigger.querySelector( 'button,a,[role="button"]' ) || trigger;
    }
    // A source close control is a native button once converted, so it is
    // recognized by its accessible name as well as the explicit marker.
    function closeControl( target ) {
        var control = target.closest && target.closest( '[data-blocks-engine-dialog-close],button,a,[role="button"]' );
        if ( ! control ) return null;
        if ( control.hasAttribute( 'data-blocks-engine-dialog-close' ) ) return control;
        var label = ( ( control.getAttribute( 'aria-label' ) || '' ) + ' ' + ( control.getAttribute( 'title' ) || '' ) ).toLowerCase();
        var text = ( control.textContent || '' ).trim().toLowerCase();
        return label.indexOf( 'close' ) > -1 || [ 'close', 'x', '\u00d7' ].indexOf( text ) > -1 ? control : null;
    }
    function mount( dialog ) {
        if ( dialog.dataset.blocksEngineMounted ) return;
        var triggers = ( dialog.getAttribute( 'data-blocks-engine-triggers' ) || '' ).split( /\s+/ ).map( function( id ) { return document.getElementById( id ); } ).filter( Boolean );
        if ( ! triggers.length ) return;
        dialog.dataset.blocksEngineMounted = 'true';
        var ancestors = triggers.map( function( trigger ) { return boundAncestors( dialog, trigger ); } );
        // The capture observed a dropdown: it opens without making the page
        // inert, its source trigger toggles it, and Escape returns focus to
        // that trigger. Anything else keeps the modal contract.
        var dropdown = 'dropdown' === dialog.getAttribute( 'data-blocks-engine-presentation' );
        var opener = null;
        function expanded( value ) { if ( dropdown ) triggers.forEach( function( trigger ) { control( trigger ).setAttribute( 'aria-expanded', value ? 'true' : 'false' ); } ); }
        function close() { expanded( false ); if ( dialog.close ) dialog.close(); else { dialog.removeAttribute( 'open' ); dialog.dispatchEvent( new Event( 'close' ) ); } }
        expanded( false );
        dialog.addEventListener( 'close', function() { expanded( false ); } );
        if ( dropdown ) document.addEventListener( 'keydown', function( event ) {
            if ( 'Escape' !== event.key || ! dialog.hasAttribute( 'open' ) ) return;
            event.preventDefault();
            close();
            if ( opener ) control( opener ).focus();
        } );
        if ( dropdown ) window.addEventListener( 'resize', function() {
            if ( dialog.hasAttribute( 'open' ) && opener && ! control( opener ).getClientRects().length ) close();
        } );
        triggers.forEach( function( trigger ) { control( trigger ).addEventListener( 'keydown', function( event ) {
            if ( event.target !== control( trigger ) || control( trigger ).matches( 'button,a' ) || [ 'Enter', ' ' ].indexOf( event.key ) < 0 ) return;
            event.preventDefault();
            control( trigger ).click();
        } ); } );
        var selection = JSON.parse( dialog.getAttribute( 'data-blocks-engine-gallery-selection' ) || '[]' );
        triggers.forEach( function( trigger ) { trigger.addEventListener( 'click', function( event ) {
            var binding = selection.find( function( item ) { return item.triggerId === trigger.id; } );
            var index;
            if ( binding ) {
                if ( ! event.target.closest( 'img' ) ) return;
                var root = trigger.closest( '.blocks-engine-authored-carousel' );
                var track = root && root.querySelector( '.blocks-engine-authored-carousel__track' );
                if ( ! track ) return;
                var source = Array.from( track.children ).findIndex( function( slide ) { return slide.contains( event.target ); } );
                index = binding.indices[ source ];
                if ( ! Number.isInteger( index ) ) return;
            }
            event.preventDefault();
            if ( dropdown ) {
                if ( dialog.hasAttribute( 'open' ) ) { close(); return; }
                opener = trigger;
                applyAncestors( dialog, ancestors[ triggers.indexOf( trigger ) ] );
                if ( 'under-header' === dialog.getAttribute( 'data-blocks-engine-placement' ) ) placeDropdown( dialog, trigger );
                if ( dialog.show ) dialog.show(); else dialog.setAttribute( 'open', '' );
                expanded( true );
                return;
            }
            if ( ! dialog.open ) applyAncestors( dialog, ancestors[ triggers.indexOf( trigger ) ] );
            if ( dialog.showModal ) dialog.showModal(); else dialog.setAttribute( 'open', '' );
            if ( binding ) {
                var carousel = dialog.querySelector( '.blocks-engine-authored-carousel' );
                if ( carousel ) carousel.dispatchEvent( new CustomEvent( 'blocks-engine-carousel-select', { detail: { index: index } } ) );
            }
        } ); } );
        // A source close control inside the panel still closes it. Outside
        // clicks are not given a meaning the capture did not observe.
        dialog.addEventListener( 'click', function( event ) { if ( ( ! dropdown && event.target === dialog ) || closeControl( event.target ) ) close(); } );
    }
    function mountAll() { document.querySelectorAll( 'dialog[data-blocks-engine-triggers],div[data-blocks-engine-presentation="dropdown"][data-blocks-engine-triggers]' ).forEach( mount ); }
    if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', mountAll ); else mountAll();
} )();
JS;

        // A closed native dialog is out of layout. Author display utilities
        // (`.grid`, `.flex`) on the dialog would otherwise override the user
        // agent's `dialog:not([open]){display:none}` and render it permanently.
        // A captured menu panel carries the source's own classes, but not the
        // wrapper that painted it. Replace the user agent's white, centred,
        // black-on-white box with a full-width panel under the header. The
        // rules have no specificity, so the source classes still win.
        // A dropdown kept at its observed source place flows there like the
        // source panel did: only the user agent's dialog box is reset.
        // A modal whose source panel positions itself keeps the source's own
        // placement: the user agent's box geometry (padding, border, auto
        // margins, insets and size caps) is reset under its rules. Canvas
        // paint stays as the backdrop for panel layers that paint over it.
        $style = 'dialog[data-blocks-engine-triggers]:not([open]){display:none!important}'
            . 'div[data-blocks-engine-presentation="dropdown"][data-blocks-engine-triggers]:not([open]){display:none!important}'
            . ':where(dialog[data-blocks-engine-presentation="dropdown"][data-blocks-engine-placement="under-header"]){position:fixed;top:var(--blocks-engine-dropdown-top,0px);left:0;width:100%;max-width:none;max-height:calc(100vh - var(--blocks-engine-dropdown-top,0px));margin:0;overflow-y:auto;z-index:var(--blocks-engine-dropdown-layer,auto);background-color:var(--blocks-engine-dropdown-background,Canvas);-webkit-backdrop-filter:var(--blocks-engine-dropdown-backdrop,none);backdrop-filter:var(--blocks-engine-dropdown-backdrop,none);color:inherit}'
            . ':where(dialog[data-blocks-engine-presentation="dropdown"][data-blocks-engine-placement="in-place"]){position:static;inset:auto;width:auto;height:auto;max-width:none;max-height:none;margin:0;padding:0;border-width:0;background-color:transparent;color:inherit;overflow:visible}'
            . ':where(dialog:not([data-blocks-engine-presentation])[data-blocks-engine-placement="source"]){inset:auto;max-width:none;max-height:none;margin:0;padding:0;border-width:0}';

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
                'style' => 'file:./style.css',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false),
            ),
            'assets' => array(
                'style.css' => $style,
                'index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__', '__DIALOG_ANCESTOR_UNVERIFIED__'), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), CaptureEvidence::DIALOG_ANCESTOR_UNVERIFIED), $editor),
            ),
            'view_js' => str_replace('__ANCESTOR_ATTRIBUTE_PATTERN__', self::ANCESTOR_ATTRIBUTE_PATTERN, $view),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element')),
        );
    }
}
