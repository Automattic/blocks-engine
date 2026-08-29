<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the typed companion block for bounded external visual iframe surfaces. */
final class VisualIframeBlockGenerator
{
    public const LOCAL_NAME = 'visual-iframe';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Embedded Content',
            'category' => 'embed',
            'description' => 'A bounded external visual surface.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'src' => array( 'type' => 'string', 'default' => '' ),
                'title' => array( 'type' => 'string', 'default' => '' ),
                'width' => array( 'type' => 'string', 'default' => '' ),
                'height' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'allow' => array( 'type' => 'string', 'default' => '' ),
                'loading' => array( 'type' => 'string', 'default' => '' ),
                'sandbox' => array( 'type' => 'string', 'default' => '' ),
                'referrerPolicy' => array( 'type' => 'string', 'default' => '' ),
                'allowFullScreen' => array( 'type' => 'boolean', 'default' => false ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $namespace = strstr($blockName, '/', true) ?: '';
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var attributes = __BLOCK_ATTRIBUTES__;
    function iframeProps( attributes, editor ) {
        var props = editor ? blockEditor.useBlockProps() : {};
        [ 'src', 'title', 'width', 'height', 'allow', 'loading', 'sandbox', 'referrerPolicy' ].forEach( function( name ) { if ( attributes[ name ] ) { props[ name ] = attributes[ name ]; } } );
        if ( attributes.className ) { props.className = attributes.className; }
        if ( attributes.allowFullScreen ) { props.allowFullScreen = true; }
        return props;
    }
    function edit( props ) { return createElement( 'iframe', iframeProps( props.attributes, true ) ); }
    function save( props ) { return createElement( 'iframe', iframeProps( props.attributes, false ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;

        return array( 'index.js' => str_replace(
            array( '__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__' ),
            array( $blockName, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ),
            $script
        ) );
    }

    /** @param array<string, mixed> $attributes */
    public function markup(array $attributes): string
    {
        $names = array(
            'className' => 'class', 'src' => 'src', 'title' => 'title', 'width' => 'width', 'height' => 'height',
            'allow' => 'allow', 'loading' => 'loading', 'sandbox' => 'sandbox', 'referrerPolicy' => 'referrerpolicy',
        );
        $markup = '<iframe';
        foreach ( $names as $attribute => $htmlName ) {
            $value = trim((string) ($attributes[$attribute] ?? ''));
            if ( '' !== $value ) {
                $markup .= ' ' . $htmlName . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        if ( true === ($attributes['allowFullScreen'] ?? false) ) {
            $markup .= ' allowfullscreen=""';
        }

        return $markup . '></iframe>';
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ),
        );
    }
}
