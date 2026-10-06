<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\DocumentRootAttributes;
use InvalidArgumentException;

/** Authored document-root selector identity, distinct from font presets. */
final class DocumentRootContext
{
    /** @return array<string,string> */
    public static function fromHtml(string $html, string $tagName = 'html'): array
    {
        return DocumentRootAttributes::fromHtml($html, $tagName);
    }

    /** @return array{root_attributes:array<string,string>,body_attributes:array<string,string>} */
    public static function metadataFromHtml(string $html): array
    {
        $attributes = DocumentRootAttributes::forDocument($html);
        return array('root_attributes' => $attributes['html'], 'body_attributes' => $attributes['body']);
    }

    public static function assertValid(mixed $attributes): void
    {
        if (!is_array($attributes)) throw new InvalidArgumentException('Document root context must be an attribute map.');
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !DocumentRootAttributes::isSelectorAttribute($name) || !is_string($value)) throw new InvalidArgumentException('Document root selector identity is invalid.');
        }
    }

    /** @param array<int,array<string,mixed>> $pages */
    public static function needsCanvas(array $pages): bool
    {
        return (bool) array_filter($pages, static fn(array $page): bool => empty($page['synthetic']) && array() !== array_diff_key($page['document_metadata']['body_attributes'] ?? array(), array('class' => true)));
    }

    /** @param array<int,array<string,mixed>> $pages @param list<string> $bodyClassCollisions */
    public static function bootstrap(array $pages, array $bodyClassCollisions = array()): string
    {
        $rows = array();
        foreach ($pages as $page) {
            $attributes = $page['document_metadata']['root_attributes'] ?? array();
            $bodyAttributes = $page['document_metadata']['body_attributes'] ?? array();
            self::assertValid($attributes);
            self::assertValid($bodyAttributes);
            if ((array() === $attributes && array() === $bodyAttributes) || !empty($page['synthetic'])) continue;
            $rows[] = array('identity' => $page['reconciliation_identity'], 'path' => trim((string) ($page['route']['path'] ?? ''), '/'), 'front_page' => !empty($page['entrypoint']), 'attributes' => $attributes, 'body_attributes' => $bodyAttributes);
        }
        if (array() === $rows && array() === $bodyClassCollisions) return '';
        $code = <<<'PHP'
$blocks_engine_document_attributes = static function ( string $element ) use ( &$blocks_engine_document_roots ): array {
    $id = is_singular() ? get_queried_object_id() : 0;
    $identity = $id ? get_post_meta( $id, '_blocks_engine_reconciliation_identity', true ) : '';
    foreach ( $blocks_engine_document_roots as $row ) {
        if ( '' !== $identity ? $identity === $row['identity'] : ( ( $row['front_page'] && is_front_page() ) || ( is_page() && $row['path'] === trim( get_page_uri( $id ), '/' ) ) ) ) return $row[ 'body' === $element ? 'body_attributes' : 'attributes' ];
    }
    return array();
};
add_filter( 'language_attributes', static function ( string $output ) use ( $blocks_engine_document_attributes ): string {
    $attributes = $blocks_engine_document_attributes( 'html' );
    if ( array() === $attributes ) return $output;
    $tag = new WP_HTML_Tag_Processor( '<html ' . $output . '>' );
    if ( ! $tag->next_tag( 'HTML' ) ) return $output;
    foreach ( $attributes as $name => $value ) {
        if ( 'class' === $name ) foreach ( preg_split( '/\s+/', $value ) ?: array() as $class ) $tag->add_class( $class );
        else $tag->set_attribute( $name, $value );
    }
    return substr( $tag->get_updated_html(), 6, -1 );
} );
add_filter( 'body_class', static function ( array $classes ) use ( $blocks_engine_document_attributes, &$blocks_engine_body_class_collisions ): array {
    $source_classes = preg_split( '/\s+/', trim( $blocks_engine_document_attributes( 'body' )['class'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
    $classes = array_diff( $classes, array_diff( $blocks_engine_body_class_collisions, $source_classes ) );
    return array_values( array_unique( array_merge( $classes, $source_classes ) ) );
} );
add_filter( 'template_include', static function ( string $template ) use ( $blocks_engine_document_attributes ): string {
    // Core's canvas exposes body_class(), but has no body-attribute hook.
    // Use the same native block rendering lifecycle when selector attributes
    // beyond classes must be present before any authored script runs.
    if ( wp_normalize_path( $template ) === wp_normalize_path( ABSPATH . WPINC . '/template-canvas.php' ) && array_diff_key( $blocks_engine_document_attributes( 'body' ), array( 'class' => true ) ) ) return get_theme_file_path( 'document-canvas.php' );
    return $template;
} );
add_filter( 'block_editor_settings_all', static function ( array $settings, $context ) use ( &$blocks_engine_document_roots ): array {
    if ( empty( $context->post->ID ) ) return $settings;
    $identity = get_post_meta( $context->post->ID, '_blocks_engine_reconciliation_identity', true );
    foreach ( $blocks_engine_document_roots as $row ) {
        if ( $identity !== $row['identity'] ) continue;
        // Content assets execute in Gutenberg's iframe, whose body is the
        // native canvas ancestor. Document context never enters saved blocks.
        $state = wp_json_encode( array( 'html' => $row['attributes'], 'body' => $row['body_attributes'] ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        $settings['__unstableResolvedAssets']['body'] = ( $settings['__unstableResolvedAssets']['body'] ?? '' ) . '<script>(function(){var state=' . $state . ';function apply(){if(!document.body||!document.body.classList.contains("editor-styles-wrapper"))return;Object.keys(state).forEach(function(name){var node=name==="html"?document.documentElement:document.body;Object.keys(state[name]).forEach(function(key){var value=state[name][key];if(key==="class"){value.split(/\s+/).filter(Boolean).forEach(function(token){if(!node.classList.contains(token))node.classList.add(token);});}else if(node.getAttribute(key)!==value)node.setAttribute(key,value);});});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",apply,{once:true});else apply();new MutationObserver(apply).observe(document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:["class"]});})();</script>';
        break;
    }
    return $settings;
}, 20, 2 );
PHP;
        // Incremental imports append bootstrap files. One runtime owner reads
        // their combined route registry; later batches cannot replace the
        // canvas resolver or strip another batch's genuine body classes.
        return "global \$blocks_engine_document_roots, \$blocks_engine_body_class_collisions, \$blocks_engine_document_attributes;\n"
            . '$blocks_engine_document_roots = array_merge( ' . var_export($rows, true) . ', $blocks_engine_document_roots ?? array() );' . "\n"
            . '$blocks_engine_body_class_collisions = array_values( array_unique( array_merge( $blocks_engine_body_class_collisions ?? array(), ' . var_export($bodyClassCollisions, true) . ' ) ) );' . "\n"
            . "if ( ! isset( \$blocks_engine_document_attributes ) ) {\n" . $code . "\n}\n";
    }

    public static function canvas(): string
    {
        return <<<'PHP'
<?php
// WordPress's block-template canvas with route-owned document attributes.
$template_html = get_the_block_template_html();
global $blocks_engine_document_attributes;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?><?php
foreach ( array_diff_key( $blocks_engine_document_attributes( 'body' ), array( 'class' => true ) ) as $name => $value ) echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
?>>
<?php wp_body_open(); ?>
<?php echo $template_html; ?>
<?php wp_footer(); ?>
</body>
</html>
PHP;
    }
}
