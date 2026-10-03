<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/** Small Interactivity primitives around one native, owner-editable tree. */
final class CollectionFilterBlockGenerator
{
    public const ROOT = 'collection-filter';
    public const FIELD = 'collection-filter-field';
    public const CHOICE = 'collection-filter-choice';
    public const CHOICES = 'collection-filter-choices';
    public const EMPTY = 'collection-filter-empty';

    public function definition(string $namespace, string $local): array
    {
        $name = $namespace . '/' . $local;
        $store = $namespace . '/' . self::ROOT;
        $attributes = array(
            'className' => array('type' => 'string', 'default' => ''),
            'anchor' => array('type' => 'string', 'default' => ''),
            'sourceStyle' => array('type' => 'object'),
        );
        if (self::ROOT === $local) $attributes += array('tagName' => array('type' => 'string', 'default' => 'div'), 'items' => array('type' => 'array', 'default' => array()), 'initialCategory' => array('type' => 'number', 'default' => 0), 'mode' => array('type' => 'string', 'default' => 'category-and-query'), 'order' => array('type' => 'array', 'default' => array()), 'categoryOrders' => array('type' => 'array', 'default' => array()));
        if (self::CHOICES === $local) $attributes += array('tagName' => array('type' => 'string', 'default' => 'div'));
        if (self::FIELD === $local) $attributes += array('inputType' => array('type' => 'string', 'default' => 'search'), 'placeholder' => array('type' => 'string', 'default' => ''), 'ariaLabel' => array('type' => 'string', 'default' => ''), 'value' => array('type' => 'string', 'default' => ''));
        if (self::CHOICE === $local) $attributes += array('tagName' => array('type' => 'string', 'default' => 'button'), 'label' => array('type' => 'string', 'default' => ''), 'ariaLabel' => array('type' => 'string', 'default' => ''), 'role' => array('type' => 'string', 'default' => ''), 'tabIndex' => array('type' => 'number'), 'index' => array('type' => 'number', 'default' => 0), 'initial' => array('type' => 'boolean', 'default' => false), 'active' => array('type' => 'object'), 'inactive' => array('type' => 'object'));
        $editor = <<<'JS'
( function( blocks, editor, components, element ) {
    var el = element.createElement;
    var role = __ROLE__;
    var store = __STORE__;

    function common( attrs ) {
        return {
            className: attrs.className || undefined,
            id: attrs.anchor || undefined,
            style: Object.keys( attrs.sourceStyle || {} ).length ? attrs.sourceStyle : undefined,
        };
    }

    function context( attrs ) {
        return role === 'collection-filter'
            ? { query: '', category: attrs.initialCategory || 0, items: attrs.items || [], matchCount: ( attrs.items || [] ).length, mode: attrs.mode || 'category-and-query', order: attrs.order || [], categoryOrders: attrs.categoryOrders || [] }
            : { choiceIndex: attrs.index, active: attrs.active, inactive: attrs.inactive };
    }

    function saveProps( attrs ) {
        var props = common( attrs );
        if ( role === 'collection-filter' ) {
            props.className = ( ( props.className || '' ) + ' blocks-engine-collection-scope' ).trim();
            props[ 'data-wp-interactive' ] = store;
            props[ 'data-wp-context' ] = JSON.stringify( context( attrs ) );
            props[ 'data-wp-init' ] = 'callbacks.init';
            props[ 'data-wp-watch' ] = 'callbacks.refresh';
        }
        if ( role === 'collection-filter-field' ) {
            props.type = attrs.inputType || 'search';
            props.placeholder = attrs.placeholder || undefined;
            props[ 'aria-label' ] = attrs.ariaLabel || undefined;
            props.value = attrs.value || '';
            props[ 'data-wp-on--input' ] = store + '::actions.query';
        }
        if ( role === 'collection-filter-choice' ) {
            var state = attrs.initial ? attrs.active : attrs.inactive;
            if ( ( attrs.tagName || 'button' ) === 'button' ) props.type = 'button';
            props.className = ( state && state.className ) || undefined;
            props.style = ( state && state.style ) || undefined;
            props[ 'aria-label' ] = attrs.ariaLabel || undefined;
            if ( attrs.role ) props.role = attrs.role;
            if ( Number.isInteger( attrs.tabIndex ) ) props.tabIndex = attrs.tabIndex;
            props[ 'aria-pressed' ] = String( !! attrs.initial );
            props[ 'aria-selected' ] = state && state.selected || undefined;
            props[ 'data-state' ] = state && state.dataState || undefined;
            props[ 'data-wp-context' ] = JSON.stringify( context( attrs ) );
            props[ 'data-wp-on--click' ] = store + '::actions.choose';
            if ( ( attrs.tagName || 'button' ) !== 'button' ) props[ 'data-wp-on--keydown' ] = store + '::actions.chooseKey';
            if ( Number.isInteger( attrs.active && attrs.active.tabIndex ) || Number.isInteger( attrs.inactive && attrs.inactive.tabIndex ) ) props[ 'data-wp-bind--tabindex' ] = store + '::state.choiceTabIndex';
            props[ 'data-wp-bind--class' ] = store + '::state.choiceClass';
            props[ 'data-wp-bind--style' ] = store + '::state.choiceStyle';
            props[ 'data-wp-bind--aria-pressed' ] = store + '::state.choicePressed';
            props[ 'data-wp-bind--aria-selected' ] = store + '::state.choiceSelected';
            props[ 'data-wp-bind--data-state' ] = store + '::state.choiceDataState';
        }
        if ( role === 'collection-filter-choices' ) {
            props[ 'data-wp-bind--hidden' ] = store + '::state.choicesHidden';
        }
        if ( role === 'collection-filter-empty' ) {
            props.hidden = true;
            props[ 'data-wp-bind--hidden' ] = store + '::state.hasMatches';
        }
        return props;
    }

    blocks.registerBlockType( __NAME__, {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false, interactivity: true },
        edit: function( props ) {
            var attrs = props.attributes;
            var inspector;
            if ( role === 'collection-filter-field' ) {
                inspector = el( editor.InspectorControls, null,
                    el( components.PanelBody, { title: 'Collection search' },
                        el( components.TextControl, {
                            label: 'Placeholder', value: attrs.placeholder,
                            onChange: function( value ) { props.setAttributes( { placeholder: value } ); },
                        } ),
                        el( components.TextControl, {
                            label: 'Accessible label', value: attrs.ariaLabel,
                            onChange: function( value ) { props.setAttributes( { ariaLabel: value } ); },
                        } )
                    )
                );
                return el( element.Fragment, null, inspector,
                    el( 'input', Object.assign( {}, editor.useBlockProps( common( attrs ) ), {
                        type: attrs.inputType || 'search', placeholder: attrs.placeholder,
                        'aria-label': attrs.ariaLabel, value: attrs.value,
                        onChange: function( event ) { props.setAttributes( { value: event.target.value } ); },
                    } ) )
                );
            }
            if ( role === 'collection-filter-choice' ) {
                return el( editor.RichText, Object.assign( {}, editor.useBlockProps( common( attrs ) ), {
                    tagName: attrs.tagName || 'button', type: ( attrs.tagName || 'button' ) === 'button' ? 'button' : undefined, role: attrs.role || undefined, tabIndex: Number.isInteger( attrs.tabIndex ) ? attrs.tabIndex : undefined, value: attrs.label, allowedFormats: [],
                    onChange: function( value ) { props.setAttributes( { label: value } ); },
                } ) );
            }
            if ( role === 'collection-filter' ) {
                inspector = el( editor.InspectorControls, null,
                    el( components.PanelBody, { title: 'Collection membership' },
                        el( components.TextareaControl, {
                            label: 'Item membership (JSON)', value: JSON.stringify( attrs.items, null, 2 ),
                            onChange: function( value ) {
                                try {
                                    var items = JSON.parse( value );
                                    if ( Array.isArray( items ) && items.every( function( item ) {
                                        return /^blocks-engine-collection-item-[a-z0-9-]+$/.test( item.marker )
                                            && Array.isArray( item.categories ) && item.categories.every( Number.isInteger );
                                    } ) ) props.setAttributes( { items: items } );
                                } catch ( error ) {}
                            },
                        } )
                    )
                );
            }
            var tag = role === 'collection-filter' || role === 'collection-filter-choices' ? attrs.tagName || 'div' : 'div';
            return el( element.Fragment, null, inspector,
                el( tag, editor.useInnerBlocksProps( editor.useBlockProps( common( attrs ) ) ) )
            );
        },
        save: function( props ) {
            var attrs = props.attributes;
            if ( role === 'collection-filter-field' ) return el( 'input', saveProps( attrs ) );
            if ( role === 'collection-filter-choice' ) {
                return el( editor.RichText.Content, Object.assign( saveProps( attrs ), { tagName: attrs.tagName || 'button', value: attrs.label } ) );
            }
            var tag = role === 'collection-filter' || role === 'collection-filter-choices' ? attrs.tagName || 'div' : 'div';
            return el( tag, editor.useInnerBlocksProps.save( saveProps( attrs ) ) );
        },
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;
        $view = <<<'JS'
import { getContext, getElement, store } from '@wordpress/interactivity';

const namespace = __STORE__;
const choice = () => {
    const context = getContext( namespace );
    return context.choiceIndex === context.category ? context.active : context.inactive;
};

// Read the owner-edited native content, including collapsed answers, while
// excluding only zero-font aria-hidden decorations such as core's toggle glyph.
export function refresh( root, context ) {
    const query = ( context.query || '' ).toLowerCase();
    const finite = context.mode === 'category-or-global-search';
    const globalSearch = finite && query !== '';
    const strip = root.querySelector( '[data-wp-bind--hidden$="::state.choicesHidden"]' );
    if ( strip ) strip.hidden = globalSearch;
    const markers = globalSearch ? ( context.order || [] ) : ( finite ? ( ( context.categoryOrders || [] )[ context.category ] || [] ) : [] );
    if ( markers.length ) arrange( root, context, markers );
    let count = 0;
    for ( const item of context.items || [] ) {
        for ( const node of root.querySelectorAll( '.' + item.marker ) ) {
            const show = globalSearch ? text( node ).includes( query ) : item.categories.includes( context.category ) && ( finite || text( node ).includes( query ) );
            node.hidden = ! show;
            if ( show ) count++;
        }
    }
    if ( context.matchCount !== count ) context.matchCount = count;
    const empty = root.querySelector( '[data-wp-bind--hidden$="::state.hasMatches"]' );
    if ( empty ) empty.hidden = count > 0;
}

const text = ( node ) => {
    const walker = document.createTreeWalker( node, NodeFilter.SHOW_TEXT );
    const parts = [];
    while ( walker.nextNode() ) {
        const parent = walker.currentNode.parentElement;
        if ( parent?.closest( '[aria-hidden="true"]' ) && parseFloat( getComputedStyle( parent ).fontSize ) === 0 ) continue;
        parts.push( walker.currentNode.textContent );
    }
    return parts.join( ' ' ).replace( /\s+/g, ' ' ).trim().toLowerCase();
};

const arrange = ( root, context, markers ) => {
    const parent = root.querySelector( '.' + markers[ 0 ] )?.parentNode;
    if ( ! parent ) return;
    for ( const marker of markers ) {
        const node = root.querySelector( '.' + marker );
        if ( node && node.parentNode === parent ) parent.appendChild( node );
    }
    for ( const item of context.items || [] ) {
        if ( markers.includes( item.marker ) ) continue;
        const node = root.querySelector( '.' + item.marker );
        if ( node && node.parentNode === parent ) parent.appendChild( node );
    }
};

const css = ( styles ) => Object.entries( styles || {} ).map( ( [ key, value ] ) => {
    let property = key.startsWith( '--' ) ? key
        : key === 'cssFloat' ? 'float' : key.replace( /[A-Z]/g, ( letter ) => '-' + letter.toLowerCase() );
    if ( /^ms[A-Z]/.test( key ) ) property = '-' + property;
    return property + ':' + value;
} ).join( ';' );

store( namespace, {
    actions: {
        query( event ) { getContext( namespace ).query = event.target.value; },
        choose() {
            const context = getContext( namespace );
            context.category = context.choiceIndex;
        },
        chooseKey( event ) {
            if ( event.key !== 'Enter' && event.key !== ' ' ) return;
            const tag = ( event.currentTarget && event.currentTarget.tagName || '' ).toLowerCase();
            if ( tag === 'button' ) return;
            event.preventDefault();
            const context = getContext( namespace );
            context.category = context.choiceIndex;
        },
    },
    state: {
        get choiceClass() { return choice()?.className || null; },
        get choiceStyle() { return css( choice()?.style ) || null; },
        get choicePressed() {
            const context = getContext( namespace );
            return String( context.choiceIndex === context.category );
        },
        get choiceSelected() { return choice()?.selected ?? null; },
        get choiceDataState() { return choice()?.dataState ?? null; },
        get choiceTabIndex() {
            const value = choice()?.tabIndex;
            return Number.isInteger( value ) ? String( value ) : null;
        },
        get choicesHidden() {
            const context = getContext( namespace );
            return context.mode === 'category-or-global-search' && ( context.query || '' ) !== '';
        },
        get hasMatches() { return getContext( namespace ).matchCount > 0; },
    },
    callbacks: {
        init() {
            const root = getElement().ref;
            const field = root.querySelector( '[data-wp-on--input="' + namespace + '::actions.query"]' );
            if ( field ) getContext( namespace ).query = field.value;
        },
        refresh() { refresh( getElement().ref, getContext( namespace ) ); },
    },
} );
JS;
        $replace = array('__NAME__' => json_encode($name), '__STORE__' => json_encode($store), '__ROLE__' => json_encode($local), '__ATTRIBUTES__' => json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $json = array('apiVersion' => 3, 'name' => $name, 'title' => match ($local) { self::ROOT => 'Filtered Collection', self::FIELD => 'Collection Search Field', self::CHOICE => 'Collection Category', self::CHOICES => 'Collection Categories', default => 'Collection Empty State' }, 'category' => 'widgets', 'editorScript' => 'file:./index.js', 'attributes' => $attributes, 'supports' => array('html' => false, 'customClassName' => false, 'interactivity' => true));
        $definition = array('name' => $local, 'block_json' => $json, 'assets' => array('index.js' => strtr($editor, $replace)), 'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element')));
        if (self::ROOT === $local) {
            $definition['block_json']['viewScriptModule'] = 'file:./view.js';
            $definition['block_json']['style'] = 'file:./style.css';
            $definition['assets']['style.css'] = '.blocks-engine-collection-scope [hidden]{display:none!important}';
            $definition['view_js'] = strtr($view, $replace);
            $definition['script_dependencies']['view.js'] = array('@wordpress/interactivity');
        }
        return $definition;
    }

    public function opening(array $attrs, string $local, string $namespace): string
    {
        $store = $namespace . '/' . self::ROOT;
        $html = array('class' => $attrs['className'] ?? '', 'id' => $attrs['anchor'] ?? '', 'style' => $this->style($attrs['sourceStyle'] ?? array()));
        $tag = 'div';
        if (self::ROOT === $local) {
            $tag = $attrs['tagName'] ?? 'div';
            $html['class'] = trim($html['class'] . ' blocks-engine-collection-scope');
            $html['data-wp-interactive'] = $store;
            $html['data-wp-context'] = json_encode(array('query' => '', 'category' => $attrs['initialCategory'] ?? 0, 'items' => $attrs['items'] ?? array(), 'matchCount' => count($attrs['items'] ?? array()), 'mode' => $attrs['mode'] ?? 'category-and-query', 'order' => $attrs['order'] ?? array(), 'categoryOrders' => $attrs['categoryOrders'] ?? array()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $html['data-wp-init'] = 'callbacks.init';
            $html['data-wp-watch'] = 'callbacks.refresh';
        } elseif (self::FIELD === $local) {
            $tag = 'input';
            $html += array('type' => $attrs['inputType'] ?? 'search', 'placeholder' => $attrs['placeholder'] ?? '', 'aria-label' => $attrs['ariaLabel'] ?? '', 'value' => $attrs['value'] ?? '', 'data-wp-on--input' => $store . '::actions.query');
        } elseif (self::CHOICE === $local) {
            $tag = in_array($attrs['tagName'] ?? 'button', array('button', 'div', 'span'), true) ? ($attrs['tagName'] ?? 'button') : 'button';
            $state = !empty($attrs['initial']) ? $attrs['active'] : $attrs['inactive'];
            $html['class'] = $state['className'] ?? '';
            $html['style'] = $this->style($state['style'] ?? array());
            $html['aria-label'] = $attrs['ariaLabel'] ?? '';
            if (in_array($attrs['role'] ?? '', array('tab', 'button'), true)) $html['role'] = $attrs['role'];
            if (is_int($attrs['tabIndex'] ?? null)) $html['tabindex'] = (string) $attrs['tabIndex'];
            $html += array('aria-pressed' => !empty($attrs['initial']) ? 'true' : 'false', 'aria-selected' => $state['selected'] ?? '', 'data-state' => $state['dataState'] ?? '', 'data-wp-context' => json_encode(array('choiceIndex' => $attrs['index'], 'active' => $attrs['active'], 'inactive' => $attrs['inactive']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'data-wp-on--click' => $store . '::actions.choose');
            if ('button' !== $tag) $html['data-wp-on--keydown'] = $store . '::actions.chooseKey';
            $html += array('data-wp-bind--class' => $store . '::state.choiceClass', 'data-wp-bind--style' => $store . '::state.choiceStyle', 'data-wp-bind--aria-pressed' => $store . '::state.choicePressed', 'data-wp-bind--aria-selected' => $store . '::state.choiceSelected', 'data-wp-bind--data-state' => $store . '::state.choiceDataState');
            if (is_int($attrs['active']['tabIndex'] ?? null) || is_int($attrs['inactive']['tabIndex'] ?? null)) $html['data-wp-bind--tabindex'] = $store . '::state.choiceTabIndex';
            if ('button' === $tag) $html['type'] = 'button';
        } elseif (self::CHOICES === $local) {
            $tag = in_array($attrs['tagName'] ?? 'div', array('div', 'nav', 'section'), true) ? $attrs['tagName'] : 'div';
            $html['data-wp-bind--hidden'] = $store . '::state.choicesHidden';
        } else {
            $html['hidden'] = true;
            $html['data-wp-bind--hidden'] = $store . '::state.hasMatches';
        }
        $output = '<' . $tag;
        foreach ($html as $key => $value) {
            if (true === $value) $output .= ' ' . $key;
            elseif ('' !== $value && null !== $value) $output .= ' ' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            elseif ('value' === $key && self::FIELD === $local) $output .= ' value=""';
        }
        return $output . ('input' === $tag ? '/>' : '>');
    }

    public function sourceStyle(string $style): array
    {
        $result = array();
        foreach (CssValueSplitter::splitTopLevel($style, array(';')) as $declaration) {
            $colon = strpos($declaration, ':');
            if (false === $colon) continue;
            $property = trim(substr($declaration, 0, $colon));
            $value = trim(substr($declaration, $colon + 1));
            if (1 !== preg_match('/^(?:--[A-Za-z0-9_-]+|[a-z-]+)$/', $property) || '' === $value || str_contains($value, '!important')) continue;
            $key = str_starts_with($property, '--') ? $property : preg_replace_callback('/-([a-z])/', static fn ($match): string => strtoupper($match[1]), $property);
            if (str_starts_with($property, '-ms-')) $key = lcfirst($key);
            if ('float' === $property) $key = 'cssFloat';
            $result[$key] = $value;
        }
        return $result;
    }

    private function style(array $style): string
    {
        $parts = array();
        foreach ($style as $key => $value) {
            $property = str_starts_with($key, '--') ? $key : preg_replace_callback('/[A-Z]/', static fn ($match): string => '-' . strtolower($match[0]), $key);
            if ('cssFloat' === $key) $property = 'float';
            if (1 === preg_match('/^ms[A-Z]/', $key)) $property = '-' . $property;
            $parts[] = $property . ':' . $value;
        }
        return implode(';', $parts);
    }
}
