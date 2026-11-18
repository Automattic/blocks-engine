<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\WordPress\AuthoredCarouselGalleryContract;

/**
 * An authored carousel of images is saved as a core Gallery so it stays
 * editable with core tools. The navigable stage the source published around
 * those images is recorded in the gallery's metadata; this filter renders it
 * in place of the gallery's own figure and loads the carousel's behavior and
 * styles from the generated carousel block type.
 */
final class CarouselGalleryRuntime
{
    public static function bootstrap(): string
    {
        return str_replace(
            array('__SHELL_KEY__', '__BLOCK_SUFFIX__', '__FIGURE_PATTERN__'),
            array(AuthoredCarouselGalleryContract::METADATA_KEY, '/' . AuthoredCarouselGalleryContract::LOCAL_NAME, var_export(AuthoredCarouselGalleryContract::FIGURE_PATTERN, true)),
            <<<'PHP'
add_filter( 'render_block_core/gallery', static function ( string $content, array $block ): string {
    $shell = $block['attrs']['metadata']['__SHELL_KEY__'] ?? null;
    if ( ! is_array( $shell ) || ! is_string( $shell['opening'] ?? null ) || ! is_string( $shell['closing'] ?? null ) ) return $content;
    if ( ! preg_match( __FIGURE_PATTERN__, $content, $match ) ) return $content;
    static $enqueued = false;
    if ( ! $enqueued ) {
        $enqueued = true;
        foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
            if ( ! str_ends_with( (string) $name, '__BLOCK_SUFFIX__' ) ) continue;
            foreach ( (array) ( $type->view_script_module_ids ?? array() ) as $module ) if ( function_exists( 'wp_enqueue_script_module' ) ) wp_enqueue_script_module( $module );
            foreach ( (array) ( $type->view_script_handles ?? array() ) as $handle ) wp_enqueue_script( $handle );
            foreach ( (array) ( $type->style_handles ?? array() ) as $handle ) wp_enqueue_style( $handle );
        }
    }
    return $shell['opening'] . $match[1] . $shell['closing'];
}, 20, 2 );
PHP
        );
    }
}
