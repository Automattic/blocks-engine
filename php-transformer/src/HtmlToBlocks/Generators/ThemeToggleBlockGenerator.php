<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;

/** Builds an editable theme control for a statically corroborated root theme contract. */
final class ThemeToggleBlockGenerator
{
    public const LOCAL_NAME = 'theme-toggle';

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        $blockName = $namespace . '/' . self::LOCAL_NAME;
        $attributes = array(
            'ariaLabel' => array('type' => 'string', 'default' => 'Toggle theme'),
            'className' => array('type' => 'string', 'default' => ''),
            'lightIcon' => array('type' => 'string', 'default' => ''),
            'darkIcon' => array('type' => 'string', 'default' => ''),
            'lightLabel' => array('type' => 'string', 'default' => 'Light Mode'),
            'darkLabel' => array('type' => 'string', 'default' => 'Dark Mode'),
            'labelClassName' => array('type' => 'string', 'default' => ''),
            'labelMarker' => array('type' => 'string', 'default' => ''),
            'rootClass' => array('type' => 'string', 'default' => 'dark'),
            'rootAttribute' => array('type' => 'string', 'default' => 'class'),
            'darkValue' => array('type' => 'string', 'default' => 'dark'),
            'lightValue' => array('type' => 'string', 'default' => 'light'),
            'defaultTheme' => array('type' => 'string', 'default' => 'dark'),
            'storageKey' => array('type' => 'string', 'default' => 'theme'),
            'themeModes' => array('type' => 'array', 'default' => array('light', 'dark')),
            'selectionButtons' => array('type' => 'array', 'default' => array()),
            'groupTag' => array('type' => 'string', 'default' => 'div'),
            'groupClassName' => array('type' => 'string', 'default' => ''),
            'groupStyle' => array('type' => 'string', 'default' => ''),
            'groupAttributes' => array('type' => 'object', 'default' => array()),
            'selectedMode' => array('type' => 'string', 'default' => 'dark'),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RawHTML = element.RawHTML;
    var RichText = blockEditor.RichText;
    function buttonProps( attrs ) { return { type: 'button', className: attrs.className || undefined, 'aria-label': attrs.ariaLabel || 'Toggle theme' }; }
    function safeStyle( style ) { var clean = {}; Object.keys( style || {} ).forEach( function( name ) { var value = style[ name ]; if ( /^(?:--[a-zA-Z0-9_-]+|[a-z][a-zA-Z0-9]*)$/.test( name ) && !/^on/i.test( name ) && !/(?:url\s*\(|expression\s*\(|javascript\s*:)/i.test( String( value ) ) && ( 'string' === typeof value || 'number' === typeof value ) ) clean[ name ] = value; } ); return clean; }
    function entryProps( entry ) { var props = {}; var safe = entry && entry.attributes || {}; Object.keys( safe ).forEach( function( name ) { if ( /^(?:role|id|title|tabIndex|dir|lang|hidden|disabled|aria-[a-z-]+|data-[a-z0-9_.:-]+)$/i.test( name ) && !/^on/i.test( name ) ) props[ name ] = safe[ name ]; } ); var clean = safeStyle( entry && entry.style ); if ( Object.keys( clean ).length ) props.style = clean; return props; }
    function safeIcon( icon ) { return /^<svg(?:\s|>)/i.test( icon || '' ) && !/(?:<\/?(?:script|style|foreignobject|iframe|object|embed|link)\b|\son[a-z]+\s*=|javascript\s*:)/i.test( icon ) ? icon : ''; }
    function icon( value, hidden ) { var svg = safeIcon( value ) ? createElement( RawHTML, null, safeIcon( value ) ) : null; return undefined === hidden ? svg : createElement( 'span', { 'data-wp-bind--hidden': hidden }, svg ); }
    function labelProps( attrs, value, onChange ) { var props = { tagName: 'span', className: attrs.labelClassName || undefined, value: value || '', allowedFormats: [] }; if ( attrs.labelMarker ) { props[ 'data-blocks-engine-richtext-marker' ] = attrs.labelMarker; } if ( onChange ) { props.onChange = onChange; } return props; }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false, interactivity: true },
        edit: function( props ) { var attrs = props.attributes; if ( attrs.selectionButtons && attrs.selectionButtons.length ) { var groupProps = Object.assign( {}, entryProps( { attributes: attrs.groupAttributes } ), { className: attrs.groupClassName || undefined } ); if ( attrs.groupStyle ) { try { groupProps.style = JSON.parse( attrs.groupStyle ); } catch ( error ) {} } return createElement( attrs.groupTag || 'div', groupProps, attrs.selectionButtons.map( function( button, index ) { return createElement( 'button', Object.assign( {}, entryProps( button ), { key: index, type: 'button', className: button.className || undefined, 'aria-label': button.ariaLabel || undefined, 'aria-pressed': button.mode === attrs.selectedMode, onClick: function() { props.setAttributes( { selectedMode: button.mode } ); } } ), icon( button.icon ) ); } ) ); } var light = 'light' === attrs.defaultTheme; var label = light ? attrs.darkLabel : attrs.lightLabel; return createElement( 'button', buttonProps( attrs ), icon( light ? attrs.darkIcon : attrs.lightIcon ), createElement( RichText, labelProps( attrs, label, function( value ) { props.setAttributes( light ? { darkLabel: value } : { lightLabel: value } ); } ) ) ); },
        save: function( props ) { var attrs = props.attributes; if ( attrs.selectionButtons && attrs.selectionButtons.length ) { var context = { rootAttribute: attrs.rootAttribute || 'class', rootClass: attrs.rootClass || 'dark', darkValue: attrs.darkValue || attrs.rootClass || 'dark', lightValue: attrs.lightValue || 'light', defaultTheme: attrs.defaultTheme || 'dark', selectedMode: attrs.selectedMode || attrs.defaultTheme || 'dark', storageKey: attrs.storageKey || 'theme', themeModes: attrs.themeModes || ['light','system','dark'] }; var groupProps = Object.assign( {}, entryProps( { attributes: attrs.groupAttributes } ), { 'data-wp-interactive': '__BLOCK_NAME__', 'data-wp-context': JSON.stringify( context ), 'data-wp-init': 'callbacks.init', className: attrs.groupClassName || undefined } ); if ( attrs.groupStyle ) { try { groupProps.style = JSON.parse( attrs.groupStyle ); } catch ( error ) {} } return createElement( attrs.groupTag || 'div', groupProps, attrs.selectionButtons.map( function( button, index ) { return createElement( 'button', Object.assign( {}, entryProps( button ), { key: index, type: 'button', className: button.className || undefined, 'aria-label': button.ariaLabel || undefined, 'aria-pressed': button.mode === attrs.selectedMode, 'data-wp-bind--aria-pressed': 'state.selected', 'data-wp-context': JSON.stringify( { mode: button.mode } ), 'data-wp-on--click': 'actions.select' } ), icon( button.icon ) ); } ) ); } var light = 'light' === attrs.defaultTheme; return createElement( 'button', Object.assign( buttonProps( attrs ), { 'data-wp-interactive': '__BLOCK_NAME__', 'data-wp-context': JSON.stringify( { rootClass: attrs.rootClass || 'dark', defaultTheme: attrs.defaultTheme || 'dark', dark: ! light, lightLabel: attrs.lightLabel || 'Light Mode', darkLabel: attrs.darkLabel || 'Dark Mode', storageKey: attrs.storageKey || 'theme', themeModes: attrs.themeModes || ['light','dark'] } ), 'data-wp-init': 'callbacks.init', 'data-wp-on--click': 'actions.toggle' } ), icon( attrs.lightIcon, 'state.hideLightIcon' ), icon( attrs.darkIcon, 'state.hideDarkIcon' ), createElement( RichText.Content, Object.assign( labelProps( attrs, light ? ( attrs.darkLabel || 'Dark Mode' ) : ( attrs.lightLabel || 'Light Mode' ), null ), { 'data-wp-text': 'state.label' } ) ) ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        $editor = str_replace("lightValue: attrs.lightValue || 'light'", "lightValue: null === attrs.lightValue ? 'light' : attrs.lightValue", $editor);
        $editor = str_replace('groupProps.style = JSON.parse( attrs.groupStyle )', 'groupProps.style = safeStyle( JSON.parse( attrs.groupStyle ) )', $editor);
        $view = <<<'JS'
import { getContext, store } from '@wordpress/interactivity';

const applyTheme = ( context, dark ) => {
    const root = document.documentElement;
    const attribute = context.rootAttribute || 'class';
    const darkValue = context.darkValue || context.rootClass || 'dark';
    const lightValue = context.lightValue || 'light';
    if ( 'class' === attribute ) {
        root.classList.toggle( darkValue, dark );
        if ( lightValue ) root.classList.toggle( lightValue, ! dark );
    } else if ( /^[a-zA-Z_:][a-zA-Z0-9:._-]*$/.test( attribute ) ) {
        root.setAttribute( attribute, dark ? darkValue : lightValue );
    }
    root.style.colorScheme = dark ? 'dark' : 'light';
};

const applyPreference = ( context, preference ) => {
    const dark = 'dark' === preference || ( 'system' === preference && context.systemDark );
    context.preference = preference;
    context.dark = dark;
    themeState.preference = preference;
    applyTheme( context, dark );
};

const watchSystemPreference = ( context ) => {
    const media = window.matchMedia( '(prefers-color-scheme: dark)' );
    context.systemDark = media.matches;
    if ( context.systemMedia && context.systemListener ) return;
    const onSchemeChange = ( event ) => {
        context.systemDark = event.matches;
        if ( 'system' === themeState.preference ) applyPreference( context, 'system' );
    };
    if ( media.addEventListener ) media.addEventListener( 'change', onSchemeChange );
    else if ( media.addListener ) media.addListener( onSchemeChange );
    context.systemMedia = media;
    context.systemListener = onSchemeChange;
};

const { state: themeState } = store( '__BLOCK_NAME__', {
    actions: {
        select() {
            const context = getContext();
            const mode = context.mode;
            if ( ! [ 'light', 'system', 'dark' ].includes( mode ) ) return;
            if ( 'system' === mode ) watchSystemPreference( context );
            applyPreference( context, mode );
            try { window.localStorage.setItem( context.storageKey || 'theme', mode ); } catch ( error ) {}
        },
        toggle() {
            const context = getContext();
            if ( Array.isArray( context.themeModes ) && context.themeModes.includes( 'system' ) ) {
                const modes = context.themeModes.filter( ( mode ) => [ 'light', 'system', 'dark' ].includes( mode ) );
                const current = modes.indexOf( context.preference || context.defaultTheme || 'dark' );
                const next = modes[ ( current + 1 ) % modes.length ] || 'light';
                if ( 'system' === next ) watchSystemPreference( context );
                applyPreference( context, next );
                try { window.localStorage.setItem( context.storageKey || 'theme', next ); } catch ( error ) {}
                return;
            }
            context.dark = ! context.dark;
            themeState.preference = context.dark ? 'dark' : 'light';
            applyTheme( context, context.dark );
            try { window.localStorage.setItem( context.storageKey || 'theme', context.dark ? 'dark' : 'light' ); } catch ( error ) {}
        },
    },
    state: {
        preference: 'dark',
        get label() {
            const context = getContext();
            return context.dark ? context.lightLabel : context.darkLabel;
        },
        get hideLightIcon() {
            return ! getContext().dark;
        },
        get hideDarkIcon() {
            return getContext().dark;
        },
        get selected() {
            const context = getContext();
            return context.mode === themeState.preference;
        },
    },
    callbacks: {
        init() {
            const context = getContext();
            const rootClass = context.rootClass || 'dark';
            let preference = context.selectedMode || context.defaultTheme || 'dark';
            try {
                const stored = window.localStorage.getItem( context.storageKey || 'theme' );
                if ( ( context.themeModes || [] ).includes( stored ) ) preference = stored;
            } catch ( error ) {}
            if ( 'system' === preference ) watchSystemPreference( context );
            applyPreference( context, preference );
        },
    },
} );
JS;

        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => $blockName,
                'title' => 'Theme Toggle',
                'category' => 'widgets',
                'description' => 'Editable control for a captured light and dark theme contract.',
                'editorScript' => 'file:./index.js',
                'viewScriptModule' => 'file:./view.js',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false, 'interactivity' => true),
            ),
            'assets' => array('index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__', 'attrs.lightValue || \'light\''), array($blockName, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'undefined === attrs.lightValue ? \'light\' : attrs.lightValue'), $editor)),
            // The Interactivity store namespace must match the save markup's
            // data-wp-interactive value and the PHP-rendered markup below, or
            // the runtime silently stops binding.
            'view_js' => str_replace('__BLOCK_NAME__', $blockName, $view),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element'), 'view.js' => array('@wordpress/interactivity')),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string $blockName Fully-qualified block name; also the Interactivity store namespace.
     */
    public function markup(array $attributes, string $blockName): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $styleMapper = new StyleAttributeMapper();
        $appendSafeAttrs = static function (array $attrs) use ($escape, $styleMapper): string {
            $html = '';
            foreach ($attrs['attributes'] ?? array() as $name => $value) {
                if (is_string($name) && preg_match('/^(?:role|id|title|tabindex|dir|lang|hidden|disabled|aria-[a-z-]+|data-[a-z0-9_.:-]+)$/i', $name) && is_scalar($value)) {
                    $html .= ' ' . strtolower($name) . '="' . (is_bool($value) ? '' : $escape((string) $value)) . '"';
                }
            }
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
            $declarations = array();
            foreach ($style as $property => $value) {
                if (is_string($property) && is_scalar($value) && preg_match('/^(?:--[a-zA-Z0-9_-]+|[a-zA-Z][a-zA-Z0-9-]*)$/', $property) && ! preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:)/i', (string) $value)) {
                    $declarations[$property] = (string) $value;
                }
            }
            $mapped = $styleMapper->map($declarations);
            $style = (string) ($styleMapper->serialize($mapped['style'])['style'] ?? '');
            foreach ($mapped['leftover'] as $property => $value) {
                if (! in_array($property, array('width', 'min-width', 'max-width', 'height', 'min-height', 'max-height'), true)) continue;
                $safeLength = CssValueInspector::isAbsoluteLength((string) $value)
                    || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)%$/', (string) $value);
                if ($safeLength && ! preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:)/i', (string) $value)) $style = trim($style . ';' . $property . ':' . $value, ';');
            }
            if ('' !== $style) $html .= ' style="' . $escape($style) . '"';
            return $html;
        };
        $selectionButtons = $attributes['selectionButtons'] ?? array();
        if (is_array($selectionButtons) && count($selectionButtons) > 0) {
            $modes = is_array($attributes['themeModes'] ?? null) ? array_values(array_intersect($attributes['themeModes'], array('light', 'system', 'dark'))) : array('light', 'system', 'dark');
            $selected = (string) ($attributes['selectedMode'] ?? $attributes['defaultTheme'] ?? 'dark');
            if (! in_array($selected, $modes, true)) $selected = 'dark';
            $context = $escape((string) json_encode(array('rootAttribute' => (string) ($attributes['rootAttribute'] ?? 'class'), 'rootClass' => (string) ($attributes['rootClass'] ?? 'dark'), 'darkValue' => (string) ($attributes['darkValue'] ?? $attributes['rootClass'] ?? 'dark'), 'lightValue' => (string) ($attributes['lightValue'] ?? 'light'), 'defaultTheme' => (string) ($attributes['defaultTheme'] ?? 'dark'), 'selectedMode' => $selected, 'storageKey' => (string) ($attributes['storageKey'] ?? 'theme'), 'themeModes' => $modes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $tag = preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', (string) ($attributes['groupTag'] ?? 'div')) ? (string) $attributes['groupTag'] : 'div';
            $groupAttrs = is_array($attributes['groupAttributes'] ?? null) ? $attributes['groupAttributes'] : array();
            $html = '<' . $tag . ' data-wp-interactive="' . $escape($blockName) . '" data-wp-context="' . $context . '" data-wp-init="callbacks.init"';
            $class = trim((string) ($attributes['groupClassName'] ?? '') . ' ' . (string) ($groupAttrs['class'] ?? ''));
            if ('' !== $class) $html .= ' class="' . $escape($class) . '"';
            $style = (string) ($attributes['groupStyle'] ?? ($groupAttrs['style'] ?? ''));
            $styleObject = json_decode($style, true);
            if (is_array($styleObject)) {
                $declarations = array();
                foreach ($styleObject as $property => $value) {
                    if (! is_string($property) || ! is_scalar($value) || preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:)/i', (string) $value)) continue;
                    $name = str_starts_with($property, '--') ? $property : strtolower((string) preg_replace('/[A-Z]/', '-$0', $property));
                    if (! preg_match('/^(?:--[a-zA-Z0-9_-]+|[a-zA-Z][a-zA-Z0-9-]*)$/', $name)) continue;
                    $declarations[$name] = (string) $value;
                }
                $mapped = $styleMapper->map($declarations);
                $style = (string) ($styleMapper->serialize($mapped['style'])['style'] ?? '');
                foreach (array('gap', 'row-gap', 'column-gap') as $property) {
                    $value = (string) ($declarations[$property] ?? '');
                    if ('' !== $value && ! preg_match('/(?:^|;)gap:/', $style) && (CssValueInspector::isAbsoluteLength($value) || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)%$/', $value) || '0' === $value)) {
                        $style = trim($style . ';' . $property . ':' . $value, ';');
                    }
                }
                $display = strtolower((string) ($declarations['display'] ?? ''));
                if (in_array($display, array('flex', 'inline-flex', 'grid', 'inline-grid', 'block', 'inline', 'none'), true)) {
                    $style = trim($style . ';display:' . $display, ';');
                }
                foreach ($mapped['leftover'] as $property => $value) {
                    if (! in_array($property, array('width', 'min-width', 'max-width', 'height', 'min-height', 'max-height'), true)) continue;
                    $safeLength = CssValueInspector::isAbsoluteLength((string) $value)
                        || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)%$/', (string) $value);
                    if ($safeLength) $style = trim($style . ';' . $property . ':' . $value, ';');
                }
            }
            if ('' !== $style) $html .= ' style="' . $escape($style) . '"';
            unset($groupAttrs['class'], $groupAttrs['style']);
            foreach ($groupAttrs as $name => $value) {
                if (is_string($name) && preg_match('/^(?:role|id|title|tabindex|dir|lang|aria-[a-z-]+|data-[a-z0-9_.:-]+)$/i', $name) && is_scalar($value)) $html .= ' ' . $name . '="' . $escape((string) $value) . '"';
            }
            $html .= '>';
            foreach ($selectionButtons as $button) {
                if (! is_array($button)) continue;
                $mode = (string) ($button['mode'] ?? '');
                $html .= '<button type="button"';
                if (! empty($button['className'])) $html .= ' class="' . $escape((string) $button['className']) . '"';
                if (! empty($button['ariaLabel'])) $html .= ' aria-label="' . $escape((string) $button['ariaLabel']) . '"';
                $html .= $appendSafeAttrs($button);
                $html .= ' aria-pressed="' . ($mode === $selected ? 'true' : 'false') . '" data-wp-context="' . $escape((string) json_encode(array('mode' => $mode), JSON_THROW_ON_ERROR)) . '" data-wp-bind--aria-pressed="state.selected" data-wp-on--click="actions.select">' . $this->safeIcon((string) ($button['icon'] ?? '')) . '</button>';
            }
            return $html . '</' . $tag . '>';
        }
        $defaultTheme = 'light' === ($attributes['defaultTheme'] ?? '') ? 'light' : 'dark';
        $lightLabel = (string) ($attributes['lightLabel'] ?? 'Light Mode');
        $darkLabel = (string) ($attributes['darkLabel'] ?? 'Dark Mode');
        $marker = $this->safeToken((string) ($attributes['labelMarker'] ?? ''));
        $themeModes = is_array($attributes['themeModes'] ?? null) ? array_values(array_intersect($attributes['themeModes'], array('light', 'system', 'dark'))) : array('light', 'dark');
        if (count($themeModes) < 2) $themeModes = array('light', 'dark');
        $context = $escape((string) json_encode(array('rootClass' => (string) ($attributes['rootClass'] ?? 'dark'), 'defaultTheme' => $defaultTheme, 'dark' => 'dark' === $defaultTheme, 'lightLabel' => $lightLabel, 'darkLabel' => $darkLabel, 'storageKey' => (string) ($attributes['storageKey'] ?? 'theme'), 'themeModes' => $themeModes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return '<button type="button"'
            . ('' !== ($attributes['className'] ?? '') ? ' class="' . $escape((string) $attributes['className']) . '"' : '')
            . ' aria-label="' . $escape((string) ($attributes['ariaLabel'] ?? 'Toggle theme')) . '"'
            . ' data-wp-interactive="' . $escape($blockName) . '" data-wp-context="' . $context . '" data-wp-init="callbacks.init" data-wp-on--click="actions.toggle">'
            . '<span data-wp-bind--hidden="state.hideLightIcon">' . $this->safeIcon((string) ($attributes['lightIcon'] ?? '')) . '</span>'
            . '<span data-wp-bind--hidden="state.hideDarkIcon">' . $this->safeIcon((string) ($attributes['darkIcon'] ?? '')) . '</span>'
            . '<span' . ('' !== ($attributes['labelClassName'] ?? '') ? ' class="' . $escape((string) $attributes['labelClassName']) . '"' : '') . ('' !== $marker ? ' data-blocks-engine-richtext-marker="' . $marker . '"' : '') . ' data-wp-text="state.label">' . $escape('dark' === $defaultTheme ? $lightLabel : $darkLabel) . '</span></button>';
    }

    private function safeIcon(string $icon): string
    {
        return SourceDom::isSafeSvgContent($icon) && ! preg_match('/<\/?(?:script|style|foreignobject|iframe|object|embed|link)\b|\son[a-z]+\s*=|javascript\s*:/i', $icon) ? $icon : '';
    }

    private function safeToken(string $value): string
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]+$/', $value) ? $value : '';
    }
}
