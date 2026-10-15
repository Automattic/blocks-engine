<?php
declare(strict_types=1);

/**
 * A paragraph holding a `<picture>` beside inline text (an avatar next to an
 * attribution line, as Astro/Next `<Picture>` components emit) converts to
 * native blocks: the picture keeps its `<source>` set through the shared
 * responsive media primitive, the text stays an editable paragraph with its
 * bold run, and a row group keeps the two side by side. Before, the whole
 * paragraph was preserved as core/html.
 *
 * Asserted through ArtifactCompiler so the page, template and plan outputs
 * are all covered.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$findBlock = static function (array $blocks, string $suffix) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( str_ends_with((string) ($block['blockName'] ?? ''), $suffix) ) {
            return $block;
        }
        $nested = $findBlock($block['innerBlocks'] ?? array(), $suffix);
        if ( null !== $nested ) {
            return $nested;
        }
    }
    return null;
};

$html = '<html><head></head><body><main><h1>Contact</h1>
<p class="who"><picture><source srcset="/a.avif 88w" type="image/avif" sizes="44px"><img src="/a.webp" alt="" width="240" height="240" style="aspect-ratio: 44 / 44;"></picture> <span><b>Ana</b>, front desk, books your visit.</span></p>
</main></body></html>';

$result = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array( 'website/index.html' => $html ),
))->toArray();

$everything = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
if ( str_contains($everything, 'wp:html') || str_contains($everything, 'core/html') ) {
    $failures[] = 'No output (page, template, or plan) preserves the paragraph as core/html.';
}

$row = $findBlock($result['blocks'] ?? array(), 'core/group');
$row = null !== $row ? $findBlock($row['innerBlocks'] ?? array(), 'core/group') : null;
$children = $row['innerBlocks'] ?? array();
if ( null === $row
    || 'who' !== ($row['attrs']['className'] ?? '')
    || 'flex' !== ($row['attrs']['layout']['type'] ?? '')
    || 2 !== count($children)
) {
    $failures[] = 'The paragraph becomes a row group that keeps its class: ' . (string) ($result['serialized_blocks'] ?? '');
}

$media = $children[0] ?? array();
$mediaContent = (string) ($media['attrs']['content'] ?? '');
if ( ! str_ends_with((string) ($media['blockName'] ?? ''), '/responsive-media')
    || ! str_contains($mediaContent, '<source srcset="/a.avif 88w" type="image/avif" sizes="44px">')
    || ! str_contains($mediaContent, 'src="/a.webp"')
) {
    $failures[] = 'The picture leads the row and keeps its responsive sources: ' . (string) json_encode($media);
}

$text = $children[1] ?? array();
if ( 'core/paragraph' !== ($text['blockName'] ?? '')
    || ! str_contains((string) ($text['innerHTML'] ?? ''), '<b>Ana</b>, front desk, books your visit.')
) {
    $failures[] = 'The text stays an editable paragraph with its bold run: ' . (string) json_encode($text);
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Paragraph inline picture text passed.\n";
