<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Builds the bounded companion block for responsive and linked image markup. */
final class ResponsiveMediaBlockGenerator
{
    public const LOCAL_NAME = 'responsive-media';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Responsive Media',
            'category' => 'media',
            'description' => 'An editable responsive or linked image.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'content' => array( 'type' => 'string', 'default' => '' ),
            ),
            'supports' => array( 'html' => false ),
            'render' => 'file:./render.php',
        );
    }

    /**
     * The content attribute is editable, so rendering must repeat the trust
     * boundary rather than relying on import-time sanitization alone.
     */
    public function render(): string
    {
        return <<<'PHP'
<?php
$content = is_string( $attributes['content'] ?? null ) ? $attributes['content'] : '';
$normalized = strtolower( preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+/', '', html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' );
if ( preg_match( '/<\s*(?:script|style|iframe|object|embed|svg)\b|\son[a-z]+\s*=/i', $normalized ) ) {
	return;
}
$safe_url = static function ( string $url ): bool {
	$normalized_url = strtolower( preg_replace( '/[\x00-\x20\x7f]+/', '', html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' );
	$normalized_url = rawurldecode( rawurldecode( $normalized_url ) );
	if ( '' === $normalized_url || ! preg_match( '/^([a-z][a-z0-9+.-]*):/i', $normalized_url, $scheme ) ) return '' !== $normalized_url;
	if ( in_array( strtolower( $scheme[1] ), array( 'http', 'https' ), true ) ) return true;
	return (bool) preg_match( '#^data:image/(?:avif|gif|jpeg|png|webp);base64,[a-z0-9+/=]+$#i', $normalized_url );
};
$sanitize_srcset = static function ( string $srcset ) use ( $safe_url ): string {
	$candidates = array();
	for ( $offset = 0, $length = strlen( $srcset ); $offset < $length; ) {
		while ( $offset < $length && ( ctype_space( $srcset[ $offset ] ) || ',' === $srcset[ $offset ] ) ) ++$offset;
		$url_start = $offset;
		while ( $offset < $length && ! ctype_space( $srcset[ $offset ] ) ) ++$offset;
		$url = substr( $srcset, $url_start, $offset - $url_start );
		while ( $offset < $length && ctype_space( $srcset[ $offset ] ) ) ++$offset;
		$descriptor_start = $offset;
		for ( $parentheses = 0; $offset < $length; ++$offset ) {
			if ( '(' === $srcset[ $offset ] ) ++$parentheses;
			elseif ( ')' === $srcset[ $offset ] && $parentheses > 0 ) --$parentheses;
			elseif ( ',' === $srcset[ $offset ] && 0 === $parentheses ) break;
		}
		$descriptor = trim( substr( $srcset, $descriptor_start, $offset - $descriptor_start ) );
		if ( '' !== $url && $safe_url( $url ) ) $candidates[] = $url . ( '' === $descriptor ? '' : ' ' . $descriptor );
	}
	return implode( ', ', $candidates );
};
$content = preg_replace_callback( '/\bsrcset\s*=\s*(["\'])(.*?)\1/is', static function ( array $match ) use ( $sanitize_srcset ): string {
	$srcset = $sanitize_srcset( $match[2] );
	return '' === $srcset ? '' : 'srcset=' . $match[1] . esc_attr( $srcset ) . $match[1];
}, $content ) ?? '';
$global = array( 'aria-*' => true, 'class' => true, 'data-*' => true, 'hidden' => true, 'id' => true, 'role' => true, 'style' => true, 'tabindex' => true, 'title' => true );
echo wp_kses( $content, array(
	'a' => array_merge( $global, array( 'download' => true, 'href' => true, 'rel' => true, 'target' => true ) ),
	'figure' => $global,
	'figcaption' => $global,
	'picture' => $global,
	'source' => array_merge( $global, array( 'media' => true, 'sizes' => true, 'srcset' => true, 'type' => true ) ),
	'img' => array_merge( $global, array( 'alt' => true, 'height' => true, 'loading' => true, 'longdesc' => true, 'sizes' => true, 'src' => true, 'srcset' => true, 'usemap' => true, 'width' => true ) ),
) );
PHP;
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    function edit( props ) {
        return createElement( 'div', blockEditor.useBlockProps(), createElement( components.TextareaControl, {
            label: 'Responsive media HTML',
            value: props.attributes.content || '',
            onChange: function( content ) { props.setAttributes( { content: content } ); }
        } ) );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { content: { type: 'string', default: '' } },
        supports: { html: false },
        edit: edit,
        save: function() { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array( 'index.js' => str_replace('__BLOCK_NAME__', $blockName, $script) );
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'render' => $this->render(),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ),
        );
    }
}
