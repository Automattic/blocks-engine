<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\DocumentRootAttributes;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;
use InvalidArgumentException;

/** Authored document-root selector identity, distinct from font presets. */
final class DocumentRootContext
{
    /** @return array<string,string> */
    public static function fromHtml(string $html, string $tagName = 'html'): array
    {
        return DocumentRootAttributes::fromHtml($html, $tagName);
    }

    /** @return array{root_attributes:array<string,string>,body_attributes:array<string,string>} */
    public static function metadataFromHtml(string $html): array
    {
        $attributes = DocumentRootAttributes::forDocument($html);
        return array('root_attributes' => $attributes['html'], 'body_attributes' => $attributes['body']);
    }

    public static function assertValid(mixed $attributes): void
    {
        if (!is_array($attributes)) throw new InvalidArgumentException('Document root context must be an attribute map.');
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !DocumentRootAttributes::isSelectorAttribute($name) || !is_string($value)) throw new InvalidArgumentException('Document root selector identity is invalid.');
        }
    }

    /** @param array<int,array<string,mixed>> $pages */
    public static function needsCanvas(array $pages): bool
    {
        return (bool) array_filter($pages, static fn(array $page): bool => empty($page['synthetic']) && array() !== array_diff_key($page['document_metadata']['body_attributes'] ?? array(), array('class' => true)));
    }

    /** @param array<int,array<string,mixed>> $pages */
    public static function bootstrap(array $pages): string
    {
        $rows = array();
        foreach ($pages as $page) {
            $attributes = $page['document_metadata']['root_attributes'] ?? array();
            $bodyAttributes = $page['document_metadata']['body_attributes'] ?? array();
            self::assertValid($attributes);
            self::assertValid($bodyAttributes);
            if (!empty($page['synthetic'])) continue;
            $rows[] = array('identity' => $page['reconciliation_identity'], 'path' => trim((string) ($page['route']['path'] ?? ''), '/'), 'front_page' => !empty($page['entrypoint']), 'attributes' => SourceClassIdentity::projectRoot($attributes), 'body_attributes' => SourceClassIdentity::projectRoot($bodyAttributes));
        }
        if (array() === $rows) return '';
        $code = <<<'PHP'
$blocks_engine_document_attributes = static function ( string $element ) use ( &$blocks_engine_document_roots ): array {
    $id = is_singular() ? get_queried_object_id() : 0;
    $identity = $id ? get_post_meta( $id, '_blocks_engine_reconciliation_identity', true ) : '';
    foreach ( $blocks_engine_document_roots as $row ) {
        if ( '' !== $identity ? $identity === $row['identity'] : ( ( $row['front_page'] && is_front_page() ) || ( is_page() && $row['path'] === trim( get_page_uri( $id ), '/' ) ) ) ) return $row[ 'body' === $element ? 'body_attributes' : 'attributes' ];
    }
    return array();
};
add_filter( 'language_attributes', static function ( string $output ) use ( $blocks_engine_document_attributes ): string {
    $attributes = $blocks_engine_document_attributes( 'html' );
    if ( array() === $attributes ) return $output;
    $tag = new WP_HTML_Tag_Processor( '<html ' . $output . '>' );
    if ( ! $tag->next_tag( 'HTML' ) ) return $output;
    foreach ( $attributes as $name => $value ) {
        if ( 'class' === $name ) foreach ( preg_split( '/\s+/', $value ) ?: array() as $class ) $tag->add_class( $class );
        else $tag->set_attribute( $name, $value );
    }
    return substr( $tag->get_updated_html(), 6, -1 );
} );
add_filter( 'body_class', static function ( array $classes ) use ( $blocks_engine_document_attributes ): array {
    $source_classes = preg_split( '/\s+/', trim( $blocks_engine_document_attributes( 'body' )['class'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
    return array_values( array_unique( array_merge( $classes, $source_classes ) ) );
} );
add_filter( 'template_include', static function ( string $template ) use ( $blocks_engine_document_attributes ): string {
    // Core's canvas exposes body_class(), but has no body-attribute hook.
    // Use the same native block rendering lifecycle when selector attributes
    // beyond classes must be present before any authored script runs.
    if ( wp_normalize_path( $template ) === wp_normalize_path( ABSPATH . WPINC . '/template-canvas.php' ) && array_diff_key( $blocks_engine_document_attributes( 'body' ), array( 'class' => true ) ) ) return get_theme_file_path( 'document-canvas.php' );
    return $template;
} );
add_filter( 'block_editor_settings_all', static function ( array $settings, $context ) use ( &$blocks_engine_document_roots ): array {
    if ( empty( $context->post->ID ) ) return $settings;
    $identity = get_post_meta( $context->post->ID, '_blocks_engine_reconciliation_identity', true );
    foreach ( $blocks_engine_document_roots as $row ) {
        if ( $identity !== $row['identity'] ) continue;
        // React inserts resolved body HTML without executing its script tags.
        // The parent editor script consumes page-owned settings and reconciles
        // the actual iframe roots, separately from saved block markup.
        $settings['blocksEngineDocumentContext'] = array( 'html' => $row['attributes'], 'body' => $row['body_attributes'] );
        break;
    }
    return $settings;
}, 20, 2 );
PHP;
        $code .= "\nadd_action( 'enqueue_block_editor_assets', static function (): void { wp_add_inline_script( 'wp-block-editor', " . var_export(self::editorScript(), true) . ", 'after' ); } );\n";
        // Incremental imports append bootstrap files. One runtime owner reads
        // their combined route registry. Each authoritative identity replaces
        // its earlier record, including empty state, without dropping other
        // routes or replacing the canvas resolver.
        return "global \$blocks_engine_document_roots, \$blocks_engine_document_attributes;\n"
            . '$blocks_engine_document_roots = array_replace( array_column( $blocks_engine_document_roots ?? array(), null, \'identity\' ), array_column( ' . var_export($rows, true) . ', null, \'identity\' ) );' . "\n"
            . "if ( ! isset( \$blocks_engine_document_attributes ) ) {\n" . $code . "\n}\n";
    }

    public static function editorScript(): string
    {
        return <<<'JS'
(function(){
    var documents = new WeakMap(), frames = new WeakSet();
    function state(){
        return window.wp && wp.data && wp.data.select('core/editor')
            ? wp.data.select('core/editor').getEditorSettings().blocksEngineDocumentContext || {}
            : window.blocksEngineDocumentContext || {};
    }
    function reconcile(doc){
        if(!doc.body || !doc.body.classList.contains('editor-styles-wrapper')) return;
        var record = documents.get(doc);
        if(!record){
            record = {html:{},body:{}};
            documents.set(doc, record);
            new MutationObserver(apply).observe(doc.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['class']});
        }
        var current = state();
        ['html','body'].forEach(function(name){
            var node = name === 'html' ? doc.documentElement : doc.body;
            var next = current[name] || {}, prior = record[name];
            Object.keys(prior).forEach(function(key){
                var old = prior[key];
                if(key === 'class'){
                    old.added.forEach(function(token){if(!(next.class || '').split(/\s+/).includes(token)) node.classList.remove(token);});
                }else if(!(key in next) && node.getAttribute(key) === old.value){
                    if(old.original === null) node.removeAttribute(key); else node.setAttribute(key,old.original);
                }
            });
            var owned = {};
            Object.keys(next).forEach(function(key){
                var value = next[key];
                if(key === 'class'){
                    var added = prior.class ? prior.class.added.filter(function(token){return value.split(/\s+/).includes(token);}) : [];
                    value.split(/\s+/).filter(Boolean).forEach(function(token){
                        if(!node.classList.contains(token)){node.classList.add(token); if(!added.includes(token)) added.push(token);}
                    });
                    owned.class = {added:added};
                }else{
                    owned[key] = {value:value,original:prior[key] ? prior[key].original : node.getAttribute(key)};
                    if(node.getAttribute(key) !== value) node.setAttribute(key,value);
                }
            });
            record[name] = owned;
        });
    }
    function apply(){
        reconcile(document);
        document.querySelectorAll('iframe[name="editor-canvas"]').forEach(function(frame){
            if(!frames.has(frame)){frames.add(frame);frame.addEventListener('load',apply);}
            if(frame.contentDocument) reconcile(frame.contentDocument);
        });
    }
    new MutationObserver(apply).observe(document.documentElement,{subtree:true,childList:true});
    if(window.wp && wp.data) wp.data.subscribe(apply);
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded',apply,{once:true}); else apply();
})();
JS;
    }

    public static function canvas(): string
    {
        return <<<'PHP'
<?php
// WordPress's block-template canvas with route-owned document attributes.
$template_html = get_the_block_template_html();
global $blocks_engine_document_attributes;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?><?php
foreach ( array_diff_key( $blocks_engine_document_attributes( 'body' ), array( 'class' => true ) ) as $name => $value ) echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
?>>
<?php wp_body_open(); ?>
<?php echo $template_html; ?>
<?php wp_footer(); ?>
</body>
</html>
PHP;
    }
}
