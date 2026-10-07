<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Restore source anchor identity on Core's dynamic social-link anchor, without changing Core's editable URL. */
final class SocialLinkSourceAttribution
{
    public static function bootstrap(): string
    {
        return <<<'PHP'
add_filter( 'render_block_core/social-link', static function ( string $content, array $block ): string {
    $identity = $block['attrs']['metadata']['blocksEngineSocialAnchor'] ?? null;
    if ( ! is_array( $identity ) ) return $content;
    $tag = new WP_HTML_Tag_Processor( $content );
    if ( ! $tag->next_tag( 'A' ) ) return $content;
    foreach ( $identity as $name => $value ) {
        if ( ! is_string( $value ) ) continue;
        if ( 'aria-label' === $name && is_string( $block['attrs']['label'] ?? null ) ) $value = $block['attrs']['label'];
        if ( 'class' === $name ) {
            foreach ( preg_split( '/\s+/', trim( $value ) ) ?: array() as $class ) if ( '' !== $class ) $tag->add_class( $class );
        } elseif ( in_array( $name, array( 'id', 'style', 'role', 'title', 'tabindex', 'target', 'rel' ), true ) || str_starts_with( $name, 'data-' ) || str_starts_with( $name, 'aria-' ) ) {
            $tag->set_attribute( $name, $value );
        }
    }
    return $tag->get_updated_html();
}, 10, 2 );
PHP;
    }
}
