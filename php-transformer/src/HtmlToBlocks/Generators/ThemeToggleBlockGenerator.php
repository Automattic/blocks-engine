<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

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
            'icon' => array('type' => 'string', 'default' => ''),
            'lightLabel' => array('type' => 'string', 'default' => 'Light Mode'),
            'darkLabel' => array('type' => 'string', 'default' => 'Dark Mode'),
            'labelClassName' => array('type' => 'string', 'default' => ''),
            'rootClass' => array('type' => 'string', 'default' => 'dark'),
            'defaultTheme' => array('type' => 'string', 'default' => 'dark'),
        );
        $editor = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RawHTML = element.RawHTML;
    var RichText = blockEditor.RichText;
    function buttonProps( attrs ) { return { type: 'button', className: attrs.className || undefined, 'aria-label': attrs.ariaLabel || 'Toggle theme' }; }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: __ATTRIBUTES__,
        supports: { html: false, customClassName: false, interactivity: true },
        edit: function( props ) { var attrs = props.attributes; var light = 'light' === attrs.defaultTheme; var label = light ? attrs.darkLabel : attrs.lightLabel; return createElement( 'button', buttonProps( attrs ), attrs.icon ? createElement( RawHTML, null, attrs.icon ) : null, createElement( RichText, { tagName: 'span', className: attrs.labelClassName || undefined, value: label || '', onChange: function( value ) { props.setAttributes( light ? { darkLabel: value } : { lightLabel: value } ); }, allowedFormats: [] } ) ); },
        save: function( props ) { var attrs = props.attributes; var light = 'light' === attrs.defaultTheme; return createElement( 'button', Object.assign( buttonProps( attrs ), { 'data-wp-interactive': 'blocks-engine/theme-toggle', 'data-wp-context': JSON.stringify( { rootClass: attrs.rootClass || 'dark', defaultTheme: attrs.defaultTheme || 'dark', dark: ! light, lightLabel: attrs.lightLabel || 'Light Mode', darkLabel: attrs.darkLabel || 'Dark Mode' } ), 'data-wp-init': 'callbacks.init', 'data-wp-on--click': 'actions.toggle' } ), attrs.icon ? createElement( RawHTML, null, attrs.icon ) : null, createElement( RichText.Content, { tagName: 'span', className: attrs.labelClassName || undefined, value: light ? ( attrs.darkLabel || 'Dark Mode' ) : ( attrs.lightLabel || 'Light Mode' ), 'data-wp-text': 'state.label' } ) ); }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;
        $view = <<<'JS'
import { getContext, store } from '@wordpress/interactivity';

const preferenceKey = 'blocks-engine-theme';
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
            try { window.localStorage.setItem( preferenceKey, context.dark ? 'dark' : 'light' ); } catch ( error ) {}
        },
    },
    state: {
        get label() {
            const context = getContext();
            return context.dark ? context.lightLabel : context.darkLabel;
        },
    },
    callbacks: {
        init() {
            const context = getContext();
            const rootClass = context.rootClass || 'dark';
            let dark = 'light' !== context.defaultTheme;
            try {
                const preference = window.localStorage.getItem( preferenceKey );
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
        $context = $escape((string) json_encode(array('rootClass' => (string) ($attributes['rootClass'] ?? 'dark'), 'defaultTheme' => $defaultTheme, 'dark' => 'dark' === $defaultTheme, 'lightLabel' => $lightLabel, 'darkLabel' => $darkLabel), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return '<button type="button"'
            . ('' !== ($attributes['className'] ?? '') ? ' class="' . $escape((string) $attributes['className']) . '"' : '')
            . ' aria-label="' . $escape((string) ($attributes['ariaLabel'] ?? 'Toggle theme')) . '"'
            . ' data-wp-interactive="blocks-engine/theme-toggle" data-wp-context="' . $context . '" data-wp-init="callbacks.init" data-wp-on--click="actions.toggle">'
            . (string) ($attributes['icon'] ?? '')
            . '<span' . ('' !== ($attributes['labelClassName'] ?? '') ? ' class="' . $escape((string) $attributes['labelClassName']) . '"' : '') . ' data-wp-text="state.label">' . ('dark' === $defaultTheme ? $lightLabel : $darkLabel) . '</span></button>';
    }
}
