<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/** Builds an editable theme control for a statically corroborated root theme contract. */
final class ThemeToggleBlockGenerator
{
    public const LOCAL_NAME = 'theme-toggle';
    public const NAME = 'blocks-engine/theme-toggle';

    /** @return array<string, mixed> */
    public function definition(): array
    {
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
            'defaultTheme' => array('type' => 'string', 'default' => 'dark'),
            'storageKey' => array('type' => 'string', 'default' => 'theme'),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RawHTML = element.RawHTML;
    var RichText = blockEditor.RichText;
    function buttonProps( attrs ) { return { type: 'button', className: attrs.className || undefined, 'aria-label': attrs.ariaLabel || 'Toggle theme' }; }
    function safeIcon( icon ) { return /^<svg(?:\s|>)/i.test( icon || '' ) && !/(?:<\/?(?:script|style|foreignobject|iframe|object|embed|link)\b|\son[a-z]+\s*=|javascript\s*:)/i.test( icon ) ? icon : ''; }
    function icon( value, hidden ) { return createElement( 'span', hidden ? { 'data-wp-bind--hidden': hidden } : undefined, safeIcon( value ) ? createElement( RawHTML, null, safeIcon( value ) ) : null ); }
    function labelProps( attrs, value, onChange ) { var props = { tagName: 'span', className: attrs.labelClassName || undefined, value: value || '', allowedFormats: [] }; if ( attrs.labelMarker ) { props[ 'data-blocks-engine-richtext-marker' ] = attrs.labelMarker; } if ( onChange ) { props.onChange = onChange; } return props; }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false, interactivity: true },
        edit: function( props ) { var attrs = props.attributes; var light = 'light' === attrs.defaultTheme; var label = light ? attrs.darkLabel : attrs.lightLabel; return createElement( 'button', buttonProps( attrs ), icon( light ? attrs.darkIcon : attrs.lightIcon ), createElement( RichText, labelProps( attrs, label, function( value ) { props.setAttributes( light ? { darkLabel: value } : { lightLabel: value } ); } ) ) ); },
        save: function( props ) { var attrs = props.attributes; var light = 'light' === attrs.defaultTheme; return createElement( 'button', Object.assign( buttonProps( attrs ), { 'data-wp-interactive': 'blocks-engine/theme-toggle', 'data-wp-context': JSON.stringify( { rootClass: attrs.rootClass || 'dark', defaultTheme: attrs.defaultTheme || 'dark', dark: ! light, lightLabel: attrs.lightLabel || 'Light Mode', darkLabel: attrs.darkLabel || 'Dark Mode', storageKey: attrs.storageKey || 'theme' } ), 'data-wp-init': 'callbacks.init', 'data-wp-on--click': 'actions.toggle' } ), icon( attrs.lightIcon, 'state.hideLightIcon' ), icon( attrs.darkIcon, 'state.hideDarkIcon' ), createElement( RichText.Content, Object.assign( labelProps( attrs, light ? ( attrs.darkLabel || 'Dark Mode' ) : ( attrs.lightLabel || 'Light Mode' ) ), { 'data-wp-text': 'state.label' } ) ) ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        $view = <<<'JS'
import { getContext, store } from '@wordpress/interactivity';

const applyTheme = ( rootClass, dark ) => {
    const root = document.documentElement;
    root.classList.toggle( rootClass, dark );
        root.classList.toggle( 'light', ! dark );
        root.style.colorScheme = dark ? 'dark' : 'light';
};

store( 'blocks-engine/theme-toggle', {
    actions: {
        toggle() {
            const context = getContext();
            context.dark = ! context.dark;
            applyTheme( context.rootClass || 'dark', context.dark );
            try { window.localStorage.setItem( context.storageKey || 'theme', context.dark ? 'dark' : 'light' ); } catch ( error ) {}
        },
    },
    state: {
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
    },
    callbacks: {
        init() {
            const context = getContext();
            const rootClass = context.rootClass || 'dark';
            let dark = 'light' !== context.defaultTheme;
            try {
                const preference = window.localStorage.getItem( context.storageKey || 'theme' );
                dark = 'dark' === preference || ( 'light' !== preference && dark );
            } catch ( error ) {}
            context.dark = dark;
            applyTheme( rootClass, dark );
        },
    },
} );
JS;

        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => array(
                'apiVersion' => 3,
                'name' => self::NAME,
                'title' => 'Theme Toggle',
                'category' => 'widgets',
                'description' => 'Editable control for a captured light and dark theme contract.',
                'editorScript' => 'file:./index.js',
                'viewScriptModule' => 'file:./view.js',
                'attributes' => $attributes,
                'supports' => array('html' => false, 'customClassName' => false, 'interactivity' => true),
            ),
            'assets' => array('index.js' => str_replace(array('__BLOCK_NAME__', '__ATTRIBUTES__'), array(self::NAME, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $editor)),
            'view_js' => $view,
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-element'), 'view.js' => array('@wordpress/interactivity')),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function markup(array $attributes): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $defaultTheme = 'light' === ($attributes['defaultTheme'] ?? '') ? 'light' : 'dark';
        $lightLabel = (string) ($attributes['lightLabel'] ?? 'Light Mode');
        $darkLabel = (string) ($attributes['darkLabel'] ?? 'Dark Mode');
        $marker = $this->safeToken((string) ($attributes['labelMarker'] ?? ''));
        $context = $escape((string) json_encode(array('rootClass' => (string) ($attributes['rootClass'] ?? 'dark'), 'defaultTheme' => $defaultTheme, 'dark' => 'dark' === $defaultTheme, 'lightLabel' => $lightLabel, 'darkLabel' => $darkLabel, 'storageKey' => (string) ($attributes['storageKey'] ?? 'theme')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return '<button type="button"'
            . ('' !== ($attributes['className'] ?? '') ? ' class="' . $escape((string) $attributes['className']) . '"' : '')
            . ' aria-label="' . $escape((string) ($attributes['ariaLabel'] ?? 'Toggle theme')) . '"'
            . ' data-wp-interactive="blocks-engine/theme-toggle" data-wp-context="' . $context . '" data-wp-init="callbacks.init" data-wp-on--click="actions.toggle">'
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
