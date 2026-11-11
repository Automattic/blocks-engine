<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

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
            foreach (self::trail($document) as $crumb) {
                $route = ArrayField::string($crumb, 'route');
                $label = ArrayField::string($crumb, 'label');
                if ('' !== $route && '' !== $label && isset($titles[$route]) && $label !== $titles[$route] && ! isset($labels[$route])) {
                    $labels[$route] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * Source trails keyed by post reconciliation identity (stable under any permalink structure). Core builds a post's trail from its
     * category and full title, so a post replaces the whole trail: each crumb
     * keeps the source label and links to its route, except the current item.
     *
     * Each post's own source trail is read from `metadata.breadcrumb_trail`,
     * recorded before shared chrome moves the breadcrumb into a template.
     *
     * @param list<array<string, mixed>> $pages Pages with `post_type`, `reconciliation_identity` and a recorded trail.
     * @return array<string, list<array{label: string, route: string|null}>>
     */
    public static function postTrails(array $pages): array
    {
        $trails = array();
        foreach ($pages as $page) {
            if ('post' !== ($page['post_type'] ?? null)) {
                continue;
            }
            $trail = array_values(array_filter((array) ($page['metadata']['breadcrumb_trail'] ?? array()), static fn ($crumb): bool => is_string($crumb['label'] ?? null) && '' !== $crumb['label']));
            if (array() === $trail) {
                continue;
            }
            $last = count($trail) - 1;
            $identity = (string) ($page['reconciliation_identity'] ?? '');
            if ('' === $identity) {
                continue;
            }
            // The last crumb is the current item whether or not the source links it to itself.
            $trails[$identity] = array_map(static fn (array $crumb, int $index): array => array('label' => $crumb['label'], 'route' => $index === $last || ! is_string($crumb['route'] ?? null) ? null : $crumb['route']), $trail, array_keys($trail));
        }

        return $trails;
    }

    /** @return list<array<string, mixed>> The source trail recorded on a document's breadcrumb blocks. */
    public static function trail(array $document): array
    {
        $markup = (string) ($document['canonical_block_markup'] ?? $document['block_markup'] ?? '');
        if (! str_contains($markup, 'blocksEngineBreadcrumbTrail')) {
            return array();
        }
        $trail = array();
        preg_match_all('/<!-- wp:breadcrumbs (\{.*?\}) \/-->/s', $markup, $matches);
        foreach ($matches[1] as $json) {
            $attributes = json_decode($json, true);
            foreach (is_array($attributes) ? ($attributes['metadata']['blocksEngineBreadcrumbTrail'] ?? array()) : array() as $crumb) {
                if (is_array($crumb)) {
                    $trail[] = $crumb;
                }
            }
        }

        return $trail;
    }

    /**
     * @param array<string, string>                                              $labels
     * @param array<string, list<array{label: string, route: string|null}>> $trails
     */
    public static function source(array $labels, array $trails = array()): string
    {
        return '$blocks_engine_breadcrumb_labels = ' . var_export($labels, true) . ';' . "\n"
            . '$blocks_engine_breadcrumb_post_trails = ' . var_export($trails, true) . ';' . "\n" . <<<'PHP'
add_filter( 'block_core_breadcrumbs_items', static function ( array $items ) use ( $blocks_engine_breadcrumb_labels, $blocks_engine_breadcrumb_post_trails ): array {
    if ( is_singular( 'post' ) && array() !== $blocks_engine_breadcrumb_post_trails ) {
        $post_route = (string) get_post_meta( get_queried_object_id(), '_blocks_engine_reconciliation_identity', true );
        if ( isset( $blocks_engine_breadcrumb_post_trails[ $post_route ] ) ) {
            return array_map( static fn ( array $crumb ): array => null === $crumb['route'] ? array( 'label' => $crumb['label'] ) : array( 'label' => $crumb['label'], 'url' => home_url( $crumb['route'] ) ), $blocks_engine_breadcrumb_post_trails[ $post_route ] );
        }
    }
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
