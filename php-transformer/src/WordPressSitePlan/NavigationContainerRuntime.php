<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Source list presentation belongs to the responsive panel, not its opener. */
final class NavigationContainerRuntime
{
    public static function source(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/navigation', static function ( string $content, array $block ): string {
    $classes = $block['attrs']['metadata']['blocksEngineNavigationContainer']['className'] ?? null;
    if ( ! is_string( $classes ) || '' === $classes ) return $content;
    $processor = new WP_HTML_Tag_Processor( $content );
    if ( ! $processor->next_tag( array( 'class_name' => 'wp-block-navigation__responsive-container' ) ) ) return $content;
    foreach ( preg_split( '/\s+/', trim( $classes ) ) ?: array() as $class ) {
        if ( '' !== $class ) $processor->add_class( $class );
    }
    return $processor->get_updated_html();
}, 10, 2 );
PHP;
    }
}
