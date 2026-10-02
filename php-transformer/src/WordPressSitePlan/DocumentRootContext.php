<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\DocumentRootAttributes;
use InvalidArgumentException;

/** Authored document-root selector identity, distinct from font presets. */
final class DocumentRootContext
{
    /** @return array<string,string> */
    public static function fromHtml(string $html): array
    {
        return DocumentRootAttributes::fromHtml($html);
    }

    public static function assertValid(mixed $attributes): void
    {
        if (!is_array($attributes)) throw new InvalidArgumentException('Document root context must be an attribute map.');
        foreach ($attributes as $name => $value) {
            if (!in_array($name, array('class', 'id'), true) || !is_string($value) || '' === trim($value)) throw new InvalidArgumentException('Document root selector identity is invalid.');
        }
    }

    /** @param array<int,array<string,mixed>> $pages */
    public static function bootstrap(array $pages): string
    {
        $rows = array();
        foreach ($pages as $page) {
            $attributes = $page['document_metadata']['root_attributes'] ?? array();
            self::assertValid($attributes);
            if (array() === $attributes || !empty($page['synthetic'])) continue;
            $rows[] = array('identity' => $page['reconciliation_identity'], 'path' => trim((string) ($page['route']['path'] ?? ''), '/'), 'front_page' => !empty($page['entrypoint']), 'attributes' => $attributes);
        }
        if (array() === $rows) return '';
        $code = <<<'PHP'
add_filter( 'language_attributes', static function ( string $output ) use ( $blocks_engine_document_roots ): string {
    $attributes = array();
    $id = is_singular() ? get_queried_object_id() : 0;
    $identity = $id ? get_post_meta( $id, '_blocks_engine_reconciliation_identity', true ) : '';
    foreach ( $blocks_engine_document_roots as $row ) {
        if ( '' !== $identity ? $identity === $row['identity'] : ( ( $row['front_page'] && is_front_page() ) || ( is_page() && $row['path'] === trim( get_page_uri( $id ), '/' ) ) ) ) { $attributes = $row['attributes']; break; }
    }
    if ( array() === $attributes ) return $output;
    $tag = new WP_HTML_Tag_Processor( '<html ' . $output . '>' );
    if ( ! $tag->next_tag( 'HTML' ) ) return $output;
    foreach ( $attributes as $name => $value ) {
        if ( 'class' === $name ) foreach ( preg_split( '/\s+/', $value ) ?: array() as $class ) $tag->add_class( $class );
        elseif ( null === $tag->get_attribute( $name ) ) $tag->set_attribute( $name, $value );
    }
    return substr( $tag->get_updated_html(), 6, -1 );
} );
PHP;
        return '$blocks_engine_document_roots = ' . var_export($rows, true) . ";\n" . $code . "\n";
    }
}
