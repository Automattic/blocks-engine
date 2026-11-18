<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\WordPress\AuthoredCarouselGalleryContract;

/**
 * An authored carousel is saved as a core Gallery or Group so it stays
 * editable with core tools. The navigable stage the source published around
 * its slides is recorded in the host's metadata; this filter renders it in
 * place of the host's own wrapper and loads the carousel's behavior and styles
 * from the generated carousel block type.
 */
final class CarouselGalleryRuntime
{
    public static function bootstrap(): string
    {
        return str_replace(
            array('__SHELL_KEY__', '__BLOCK_SUFFIX__', '__WRAPPER_PATTERN__', '__HOST_FILTERS__'),
            array(
                AuthoredCarouselGalleryContract::METADATA_KEY,
                '/' . AuthoredCarouselGalleryContract::LOCAL_NAME,
                var_export(AuthoredCarouselGalleryContract::WRAPPER_PATTERN, true),
                var_export(array_map(static fn (string $name): string => 'render_block_' . $name, AuthoredCarouselGalleryContract::HOST_BLOCKS), true),
            ),
            <<<'PHP'
$blocks_engine_carousel_host = static function ( string $content, array $block ): string {
    $shell = $block['attrs']['metadata']['__SHELL_KEY__'] ?? null;
    if ( ! is_array( $shell ) || ! is_string( $shell['opening'] ?? null ) || ! is_string( $shell['closing'] ?? null ) ) return $content;
    if ( ! preg_match( __WRAPPER_PATTERN__, $content, $match ) ) return $content;
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
    return $shell['opening'] . $match[2] . $shell['closing'];
};
foreach ( __HOST_FILTERS__ as $blocks_engine_carousel_filter ) add_filter( $blocks_engine_carousel_filter, $blocks_engine_carousel_host, 20, 2 );
PHP
        );
    }
}
