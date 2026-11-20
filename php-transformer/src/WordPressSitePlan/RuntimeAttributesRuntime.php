<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

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
        return str_replace(array('__DEVICE_INLINE__', '__DEVICE_CLASS__', '__VIEWPORT_ENTRANCE__', '__RESPONSIVE_FRAGMENT__', '__SUBJECT_BASE__', '__SUBJECT_CLASS_PREFIX__'), array(CaptureEvidence::DEVICE_INLINE, CaptureEvidence::DEVICE_CLASS, CaptureEvidence::VIEWPORT_ENTRANCE, CaptureEvidence::RESPONSIVE_FRAGMENT, EngineMarker::SUBJECT_BASE_CLASS, EngineMarker::SUBJECT_CLASS_PREFIX), <<<'PHP'
add_filter( 'render_block', static function ( string $content, array $block ): string {
    // RichText stays editable without introducing a published label box.
    if ( 'core/paragraph' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['metadata']['blocksEngineControlLabel'] ) ) {
        return preg_replace( '/^<p\b[^>]*>(.*)<\/p>\s*$/s', '$1', trim( $content ) );
    }
    // Core containers own CSS-drawn control boxes in the editor. On the
    // front end keep their authored box attributes, not group layout chrome.
    $box = $block['attrs']['metadata']['blocksEngineControlBox'] ?? null;
    if ( 'core/group' === ( $block['blockName'] ?? '' ) && is_array( $box ) ) {
        $processor = new WP_HTML_Tag_Processor( $content );
        if ( $processor->next_tag() ) {
            // Keep projection identities: route CSS can address these markers
            // even though the published box sheds Core's group chrome. Geometry
            // carriers are this box's own styles; on a box-tree route, where
            // the author sheet is retired, they are its only styles.
            $classes = preg_split( '/\s+/', (string) $processor->get_attribute( 'class' ) );
            $markers = array_filter( $classes, static fn ( string $class ): bool => str_starts_with( $class, 'blocks-engine-' ) || str_starts_with( $class, 'be-inline-geometry-' ) );
            $box['class'] = trim( ( $box['class'] ?? '' ) . ' ' . implode( ' ', $markers ) );
            $processor->remove_attribute( 'class' );
            $processor->remove_attribute( 'style' );
            foreach ( $box as $name => $value ) {
                if ( is_string( $name ) && is_string( $value ) && preg_match( '/^(?:class|style|id|type|name|title|tabindex|disabled|hidden|role|data-(?!wp-)[a-z0-9_.:-]+|aria-[a-z-]+)$/D', $name ) ) $processor->set_attribute( $name, $value );
            }
            $content = $processor->get_updated_html();
            if ( ! empty( $block['attrs']['metadata']['blocksEngineControlLabel'] ) ) {
                // The nested Core button is an editor-only RichText surface.
                $content = preg_replace( '/(<div\b[^>]*>)\s*<div\b[^>]*>\s*<button\b[^>]*>([\s\S]*)<\/button>\s*<\/div>\s*(<\/div>\s*)$/', '$1$2$3', $content, 1 );
            }
            if ( 'button' === ( $block['attrs']['metadata']['blocksEngineControlTagName'] ?? null ) ) {
                // Core's editor keeps a selectable group; the published control
                // must remain a native button, without an extra containing box.
                $content = preg_replace( '/^<div\b/', '<button', $content, 1 );
                $content = preg_replace( '/<\/div>\s*$/', '</button>', $content, 1 );
            }
            return $content;
        }
    }
    $attributes = $block['attrs']['metadata']['blocksEngineRuntimeAttributes'] ?? null;
    if ( ! is_array( $attributes ) || array() === $attributes || '' === $content ) return $content;
    $processor = new WP_HTML_Tag_Processor( $content );
    if ( ! $processor->next_tag() ) return $content;
    // Document shells are lowered after chrome extraction. Their source
    // attributes, including the original class list, are the runtime contract.
    if ( 'core/group' === ( $block['blockName'] ?? '' ) && array_key_exists( 'data-blocks-engine-capture-document-scope', $attributes ) ) {
        foreach ( $attributes as $name => $value ) {
            if ( is_string( $name ) && is_string( $value ) ) $processor->set_attribute( $name, $value );
        }
        return $processor->get_updated_html();
    }
    $entrance = $attributes['__VIEWPORT_ENTRANCE__'] ?? null;
    $state = is_string( $entrance ) ? json_decode( $entrance, true ) : null;
    if ( is_array( $state ) && is_array( $state['attributes'] ?? null ) ) {
        $poses = $state['attributes'];
        $class = $poses['class'] ?? null;
        unset( $poses['class'] );
        // Button motion is projected onto the link, not core's layout wrapper.
        // Class poses stay on the wrapper, which saves the source className.
        if ( 'core/button' === ( $block['blockName'] ?? '' ) && array() !== $poses ) {
            $link = new WP_HTML_Tag_Processor( $content );
            if ( $link->next_tag( array( 'class_name' => 'wp-block-button__link' ) ) ) {
                $link->set_attribute( '__VIEWPORT_ENTRANCE__', wp_json_encode( array( 'attributes' => $poses ) + $state ) );
                $content = $link->get_updated_html();
                $processor = new WP_HTML_Tag_Processor( $content );
                $processor->next_tag();
                $state['attributes'] = is_array( $class ) ? array( 'class' => $class ) : array();
            }
        }
        // Projected rules address a carrier's source class tokens through
        // subject classes, so a replayed token brings its subject class along.
        if ( is_array( $class ) && is_array( $state['attributes']['class'] ?? null ) && $processor->has_class( '__SUBJECT_BASE__' ) ) {
            foreach ( array( 'before', 'after' ) as $pose ) {
                if ( ! is_string( $class[ $pose ] ?? null ) ) continue;
                $tokens = preg_split( '/\s+/', $class[ $pose ], -1, PREG_SPLIT_NO_EMPTY );
                foreach ( $tokens as $token ) $tokens[] = '__SUBJECT_CLASS_PREFIX__' . substr( hash( 'sha256', $token ), 0, 12 );
                $state['attributes']['class'][ $pose ] = implode( ' ', $tokens );
            }
        }
        if ( array() === $state['attributes'] ) unset( $attributes['__VIEWPORT_ENTRANCE__'] );
        elseif ( $state !== json_decode( $entrance, true ) ) $attributes['__VIEWPORT_ENTRANCE__'] = wp_json_encode( $state );
    }
    foreach ( $attributes as $name => $value ) {
        if ( ! is_string( $name ) || ! is_string( $value ) || ! preg_match( '/^(?:data-[a-z0-9_.:-]+|aria-[a-z]+|role|hidden)$/D', $name ) || ( str_starts_with( $name, 'data-blocks-engine-' ) && ! in_array( $name, array( '__DEVICE_INLINE__', '__DEVICE_CLASS__', '__VIEWPORT_ENTRANCE__', '__RESPONSIVE_FRAGMENT__' ), true ) ) ) continue;
        if ( null === $processor->get_attribute( $name ) ) $processor->set_attribute( $name, '' === $value ? true : $value );
    }
    return $processor->get_updated_html();
}, 10, 2 );
PHP);
    }
}
