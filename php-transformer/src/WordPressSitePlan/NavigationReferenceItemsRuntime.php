<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * A shared navigation entity holds one copy of each item. A reference whose
 * items present differently records their classes and anchor presentation
 * ({@see NavigationEntityProjection::ITEMS_KEY}); while that reference
 * renders, its items take them, in the order the entity's items render.
 */
final class NavigationReferenceItemsRuntime
{
    public static function source(): string
    {
        return str_replace(array('__ITEMS_KEY__', '__DOCUMENT_ID__'), array(NavigationEntityProjection::ITEMS_KEY, WordPressSitePlan::DOCUMENT_ID_EXPRESSION), <<<'PHP'
$blocks_engine_navigation_items = array();
add_filter( 'render_block_data', static function ( array $block ) use ( &$blocks_engine_navigation_items ): array {
    if ( 'core/navigation' === ( $block['blockName'] ?? null ) ) $blocks_engine_navigation_items[] = $block['attrs']['metadata']['__ITEMS_KEY__'] ?? null;
    return $block;
}, 10, 1 );
add_filter( 'render_block_core/navigation', static function ( string $content ) use ( &$blocks_engine_navigation_items ): string {
    array_pop( $blocks_engine_navigation_items );
    return $content;
}, 10, 1 );
add_filter( 'block_core_navigation_render_inner_blocks', static function ( $inner_blocks ) use ( &$blocks_engine_navigation_items ) {
    $presentation = end( $blocks_engine_navigation_items );
    if ( ! is_array( $presentation ) ) return $inner_blocks;
    $items = array();
    $collect = static function ( $blocks ) use ( &$collect, &$items ): void {
        foreach ( $blocks as $block ) {
            if ( in_array( $block->name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) $items[] = $block;
            $collect( $block->inner_blocks );
        }
    };
    $collect( $inner_blocks );
    // An edited menu no longer has the recorded items; it keeps its own.
    if ( count( $items ) !== count( $presentation ) ) return $inner_blocks;
    $document = __DOCUMENT_ID__;
    $served = apply_filters( 'blocks_engine_served_route', is_front_page() ? '/' : ( 0 !== $document && 'page' === get_post_type( $document ) ? '/' . trim( (string) get_page_uri( $document ), '/' ) : null ), $document );
    foreach ( $items as $position => $block ) {
        $own = is_array( $presentation[ $position ] ) ? $presentation[ $position ] : array();
        $attrs = $block->parsed_block['attrs'] ?? array();
        // The recorded presentation holds the state the item was captured in.
        // Served in the other state, the item keeps the entity's presentation,
        // which the navigation's current and resting rules complete.
        $url = is_string( $attrs['url'] ?? null ) ? trim( $attrs['url'] ) : '';
        $current = str_contains( (string) ( $attrs['className'] ?? '' ), 'blocks-engine-current-navigation-item' )
            || ( null !== $served && '' !== $url && '/' === $url[0] && ( 1 === strlen( $url ) || '/' !== $url[1] ) && '/' . trim( (string) parse_url( $url, PHP_URL_PATH ), '/' ) === $served );
        if ( $current !== ! empty( $own['current'] ) ) continue;
        unset( $attrs['className'], $attrs['metadata']['blocksEngineNavigationAnchor'] );
        if ( is_string( $own['className'] ?? null ) ) $attrs['className'] = $own['className'];
        if ( is_array( $own['blocksEngineNavigationAnchor'] ?? null ) ) $attrs['metadata']['blocksEngineNavigationAnchor'] = $own['blocksEngineNavigationAnchor'];
        $block->parsed_block['attrs'] = $attrs;
        unset( $block->attributes );
    }
    return $inner_blocks;
}, 10, 1 );
unset( $blocks_engine_navigation_items );
PHP);
    }
}
