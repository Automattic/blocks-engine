<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Restore the source trail's labels on core/breadcrumbs. Core labels crumbs
 * with page titles; the recognizer records the source labels by resolved
 * route, and the theme filters Core's items to use them.
 */
final class BreadcrumbLabelRuntime
{
    /**
     * Source crumb labels keyed by resolved route, for crumbs whose label
     * differs from the title of the page at that route.
     *
     * @param list<array<string, mixed>> $documents Documents carrying breadcrumb blocks.
     * @param list<array<string, mixed>> $pages     Pages with `route.path` and `title`.
     * @return array<string, string>
     */
    public static function labels(array $documents, array $pages): array
    {
        $titles = array();
        foreach ($pages as $page) {
            $titles[self::route((string) ($page['route']['path'] ?? ''))] = (string) ($page['title'] ?? '');
        }
        $labels = array();
        foreach ($documents as $document) {
            $markup = (string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? '');
            if (! str_contains($markup, 'blocksEngineBreadcrumbTrail')) {
                continue;
            }
            preg_match_all('/<!-- wp:breadcrumbs (\{.*?\}) \/-->/s', $markup, $matches);
            foreach ($matches[1] as $json) {
                $attributes = json_decode($json, true);
                foreach (is_array($attributes) ? ($attributes['metadata']['blocksEngineBreadcrumbTrail'] ?? array()) : array() as $crumb) {
                    $route = is_string($crumb['route'] ?? null) ? $crumb['route'] : '';
                    $label = is_string($crumb['label'] ?? null) ? $crumb['label'] : '';
                    if ('' !== $route && '' !== $label && isset($titles[$route]) && $label !== $titles[$route] && ! isset($labels[$route])) {
                        $labels[$route] = $label;
                    }
                }
            }
        }

        return $labels;
    }

    /** @param array<string, string> $labels */
    public static function source(array $labels): string
    {
        return '$blocks_engine_breadcrumb_labels = ' . var_export($labels, true) . ';' . "\n" . <<<'PHP'
add_filter( 'block_core_breadcrumbs_items', static function ( array $items ) use ( $blocks_engine_breadcrumb_labels ): array {
    $home = (string) parse_url( home_url( '/' ), PHP_URL_PATH );
    foreach ( $items as $index => $item ) {
        $path = ! empty( $item['url'] ) ? (string) parse_url( (string) $item['url'], PHP_URL_PATH ) : '';
        if ( '' === $path && $index === count( $items ) - 1 && get_queried_object_id() ) $path = '/' . get_page_uri( get_queried_object_id() );
        if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home ) ) $path = substr( $path, strlen( rtrim( $home, '/' ) ) );
        $route = '' === trim( $path, '/' ) ? '/' : '/' . trim( $path, '/' ) . '/';
        if ( isset( $blocks_engine_breadcrumb_labels[ $route ] ) ) $items[ $index ]['label'] = $blocks_engine_breadcrumb_labels[ $route ];
    }
    return $items;
} );
PHP;
    }

    private static function route(string $path): string
    {
        return '' === trim($path, '/') ? '/' : '/' . trim($path, '/') . '/';
    }
}
