<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}" . ('' === $detail ? '' : " ({$detail})") . "\n");
    }
};

/**
 * A recurring custom element whose wrapped content diverges reads as a
 * wrapping convention and is unwrapped before author-selector preparation.
 * That is only render-neutral when no author rule addresses the instance.
 * Here the instance carries no id, class, or style; its only hook is a data
 * attribute, and author CSS keys a descendant's scroll-driven motion (and a
 * resting transform) on it:
 *
 *   [data-motion-key="band/photo"] :is(.frame):is(#photo, [id^="photo__"])
 *
 * Unwrapping the instance deletes the ancestor that compound needs, so the
 * rule silently stops matching in the copy: the photo loses its animation
 * binding and sits at a different offset than the source. The instance must
 * survive (or the rule must be re-bound) so the emitted stylesheet still
 * reaches the photo in the emitted markup.
 */
$css = '@keyframes drift{0%{transform:translateY(calc(-1 * var(--drift-to)))}100%{transform:translateY(var(--drift-to))}}'
    . '[data-motion-key="band/photo"] :is(.frame):is(#photo, [id^="photo__"]){--drift-to:-20vh;--drift-timeline:--trigger-photo;transform:translateY(40px)}'
    . '[data-motion-key="band/title"] :is(.copy):is(#title, [id^="title__"]),[data-motion-key="band/photo"] :is(.frame):is(#photo, [id^="photo__"]){animation:drift auto linear both;animation-timeline:var(--drift-timeline);view-timeline:var(--drift-timeline)}';
$html = '<!doctype html><html><head><link rel="stylesheet" href="assets/motion.css"></head><body><main><section class="band">'
    . '<motion-wrap data-motion-key="band/title"><div id="title" class="copy"><h2>Light that listens</h2></div></motion-wrap>'
    . '<motion-wrap data-motion-key="band/photo"><div id="photo" class="frame"><img src="photo.jpg" alt="A lamp" width="300" height="400"></div></motion-wrap>'
    . '<motion-wrap><p>Adapts to the room.</p></motion-wrap>'
    . '</section></main></body></html>';

$artifact = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array( 'path' => 'website/index.html', 'kind' => 'html', 'content' => $html ),
        array( 'path' => 'website/assets/motion.css', 'kind' => 'css', 'content' => $css ),
    ),
) )->toArray();

$markup = (string) ($artifact['serialized_blocks'] ?? '');
$assert('' !== $markup, 'artifact compilation serializes the page');

// Frontend stylesheets only: editor-static-state assets are an editor-canvas
// projection and deliberately neutralize motion.
$frontendCss = '';
foreach ( is_array($artifact['assets'] ?? null) ? $artifact['assets'] : array() as $asset ) {
    $path = (string) ($asset['path'] ?? '');
    if ( str_ends_with($path, '.css') && ! str_contains($path, 'editor-static-state') ) {
        $frontendCss .= "\n" . (string) ($asset['content'] ?? '');
    }
}
$assert(str_contains($frontendCss, '--drift-to:-20vh'), 'the frontend stylesheet keeps the authored motion rule');

$rendered = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$rendered->loadHTML('<!doctype html><html><body>' . preg_replace('/<!--.*?-->/s', '', $markup) . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$photo = $rendered->getElementById('photo');
$assert($photo instanceof DOMElement, 'the photo frame keeps its source identity in the rendered markup');

if ( $photo instanceof DOMElement ) {
    $style = ( new StaticCssCascade($rendered, $frontendCss) )->resolve($photo, array( 'transform', 'animation-timeline', 'view-timeline' ), array());
    $assert('translateY(40px)' === ($style['transform'] ?? ''), 'the attribute-keyed author rule still reaches the photo in the emitted markup', json_encode($style) ?: '');
    $assert('--trigger-photo' === ($style['view-timeline'] ?? '') && '--trigger-photo' === ($style['animation-timeline'] ?? ''), 'the photo keeps its scroll-driven timeline binding', json_encode($style) ?: '');
}

// The plain instance that no author rule addresses is still a wrapping
// convention and must keep unwrapping.
$assert(! str_contains($markup, '<motion-wrap>') && ! str_contains($markup, 'motion-wrap'), 'an instance no author selector addresses is still unwrapped');

if ( 0 !== $failures ) {
    fwrite(STDERR, "FAILURES: {$failures}\n");
    exit(1);
}
echo "Custom element wrapper attribute selector contract tests passed\n";
