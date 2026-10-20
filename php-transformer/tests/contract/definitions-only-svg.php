<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/contract/definitions-only-svg.html');
foreach (array($html, str_replace('overflow:hidden;', 'overflow:hidden;--shape:url("#mask-a");', str_replace('clip-path:url("#mask-a")', 'clip-path:var(--shape)', $html)), str_replace('class="frame"', 'class="frame" style="clip-path:url(#mask-a)"', $html), str_replace('<svg ', '<svg aria-hidden="true" ', $html)) as $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = (string) $result['serialized_blocks'];
    $assert(!str_contains($markup, '<!-- wp:html'), 'Definitions-only SVG must not fall back to core/html.');
    $assert(array() === $result['fallbacks'], 'Definitions and consumer have no unsupported loss.');
    $assert(str_contains($markup, '<!-- wp:image'), 'The clipped image stays editable core/image.');
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'Serialized blocks remain valid.');
    // Runtime's standalone renderer renders static blocks only. Expand the
    // audited svg-artwork renderer's content attribute before rendering the tree.
    $expand = static function (array $blocks) use (&$expand): array {
        foreach ($blocks as &$block) {
            if ('custom/svg-artwork' === ($block['blockName'] ?? null)) {
                $block['innerContent'] = array($block['attrs']['svg']);
            }
            $block['innerBlocks'] = $expand($block['innerBlocks'] ?? array());
        }
        return $blocks;
    };
    $rendered = (new Runtime())->renderBlocks($expand((new Runtime())->parseBlocks($markup)));
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    $assert(1 === preg_match('/#block-a \\.frame\\{[^}]*clip-path:(?:url\\("#mask-a"\\)|var\\(--shape\\))/', $css), 'The image frame retains its authored clip-path rule.');
    $dom = new DOMDocument();
    @$dom->loadHTML($rendered);
    $xpath = new DOMXPath($dom);
    $assert(1 === $xpath->query('//*[@id="mask-a"]')->length && 1 === $xpath->query('//*[@id="block-a"]//*[contains(concat(" ", @class, " "), " frame ")]//img')->length, 'The styled image consumer and unique fragment target resolve in the rendered document.');
    $assert(str_contains($rendered, '<clipPath id="mask-a" clipPathUnits="objectBoundingBox">') && str_contains($rendered, '<img'), 'Rendered image and correctly cased clip definition share the page document.');
    $assert(str_contains($rendered, 'width="0" height="0"'), 'The definition carrier retains its zero-size box.');
}
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$assert('passed' === ($compiled['source_reports']['editability_policy']['status'] ?? null), 'The reduced fixture passes the compiler editability policy without exemptions.');
$assert(!str_contains($compiled['serialized_blocks'], '<!-- wp:html'), 'Artifact compilation also avoids core/html.');
echo "Definitions-only SVG contract passed.\n";
