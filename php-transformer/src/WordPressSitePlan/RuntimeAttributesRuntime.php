<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

/**
 * A dynamic block (core/navigation) cannot save the source hooks a script
 * finds it by. The transformer records them in block metadata; this filter
 * renders them on the block's outer element, without replacing values the
 * block already rendered.
 */
final class RuntimeAttributesRuntime
{
    public static function source(): string
    {
        return str_replace(array('__DEVICE_INLINE__', '__VIEWPORT_ENTRANCE__', '__RESPONSIVE_FRAGMENT__'), array(CaptureEvidence::DEVICE_INLINE, CaptureEvidence::VIEWPORT_ENTRANCE, CaptureEvidence::RESPONSIVE_FRAGMENT), <<<'PHP'
add_filter( 'render_block', static function ( string $content, array $block ): string {
    // The label is editable core RichText; the source button owns its box.
    if ( 'core/paragraph' === ( $block['blockName'] ?? '' ) && true === ( $block['attrs']['metadata']['blocksEngineControlLabel'] ?? false ) ) {
        return preg_replace( '~^\\s*<p\\b[^>]*>(.*?)</p>\\s*$~s', '$1', $content ) ?? $content;
    }
    // Core containers own CSS-drawn control boxes in the editor. On the
    // front end keep their authored box attributes, not group layout chrome.
    $box = $block['attrs']['metadata']['blocksEngineControlBox'] ?? null;
    if ( 'core/group' === ( $block['blockName'] ?? '' ) && is_array( $box ) ) {
        $processor = new WP_HTML_Tag_Processor( $content );
        if ( $processor->next_tag() ) {
            $processor->remove_attribute( 'class' );
            $processor->remove_attribute( 'style' );
            foreach ( $box as $name => $value ) {
                if ( is_string( $name ) && is_string( $value ) && preg_match( '/^(?:class|style|id|type|name|title|tabindex|disabled|hidden|role|data-(?!wp-)[a-z0-9_.:-]+|aria-[a-z-]+)$/D', $name ) ) $processor->set_attribute( $name, $value );
            }
            $html = $processor->get_updated_html();
            $tag = $block['attrs']['metadata']['blocksEngineControlTag'] ?? null;
            if ( is_string( $tag ) && in_array( $tag, array( 'button', 'span', 'i', 'b', 'em', 'strong', 'small', 'div' ), true ) ) {
                $html = preg_replace( '~^(\\s*)<[a-z0-9]+\\b~i', '$1<' . $tag, $html ) ?? $html;
                $html = preg_replace( '~</[a-z0-9]+>(\\s*)$~i', '</' . $tag . '>$1', $html ) ?? $html;
            }
            return $html;
        }
    }
    $attributes = $block['attrs']['metadata']['blocksEngineRuntimeAttributes'] ?? null;
    if ( ! is_array( $attributes ) || array() === $attributes || '' === $content ) return $content;
    $processor = new WP_HTML_Tag_Processor( $content );
    if ( ! $processor->next_tag() ) return $content;
    foreach ( $attributes as $name => $value ) {
        if ( ! is_string( $name ) || ! is_string( $value ) || ! preg_match( '/^(?:data-[a-z0-9_.:-]+|aria-[a-z]+|role)$/D', $name ) || ( str_starts_with( $name, 'data-blocks-engine-' ) && ! in_array( $name, array( '__DEVICE_INLINE__', '__VIEWPORT_ENTRANCE__', '__RESPONSIVE_FRAGMENT__' ), true ) ) ) continue;
        if ( null === $processor->get_attribute( $name ) ) $processor->set_attribute( $name, '' === $value ? true : $value );
    }
    return $processor->get_updated_html();
}, 10, 2 );
PHP);
    }
}
