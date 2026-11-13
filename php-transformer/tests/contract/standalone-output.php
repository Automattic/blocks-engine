<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Issue #5802: a standalone computed result must remain a live script target.
$html = <<<'HTML'
<div class="counter" aria-label="Dhikr count">
  <span class="counter-label" id="count-label">Current count</span>
  <output class="counter-value" id="count" aria-live="polite" aria-atomic="true">0</output>
  <p id="progress">No target set</p>
</div>
<script>document.querySelector('button')?.addEventListener('click', () => { const o = document.getElementById('count'); o.textContent = String(+o.textContent + 1); });</script>
HTML;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$page = (string) (current($result['source_reports']['wordpress_site_plan']['pages'])['canonical_block_markup'] ?? '');
$assert(!str_contains($page, '<!-- wp:html'), 'The counter must not contain core/html.');
foreach ($result['diagnostics'] ?? array() as $diagnostic) {
    $code = (string) ($diagnostic['code'] ?? '');
    $assert('runtime_dependency_missing_dom_target' !== $code, 'The script target must resolve.');
    $assert(!str_contains($code, 'fallback') && 'html_unsupported_element' !== $code, 'The reproduction must not produce fallback diagnostics: ' . $code);
}

$find = static function (array $blocks) use (&$find): ?array {
    foreach ($blocks as $block) {
        if ('count' === ($block['attrs']['id'] ?? '')) return $block;
        $child = $find($block['innerBlocks'] ?? array());
        if (null !== $child) return $child;
    }
    return null;
};
$block = $find((new Runtime())->parseBlocks($page));
$assert(null !== $block && str_ends_with($block['blockName'], '/authored-input'), 'The output belongs to an editable native control block.');
$generator = new AuthoredInputBlockGenerator();
$assert(str_contains($generator->assets('custom')['index.js'], "['progress','meter','output']"), 'Editor save supports the same native output tag.');
foreach (array($page, $generator->markup($block['attrs'])) as $markup) {
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $xpath = new DOMXPath($document);
    $assert(1 === $xpath->query('//output[@id="count" and @class="counter-value" and @aria-live="polite" and @aria-atomic="true" and text()="0"]')->length, 'Saved and front-end markup retain the live output and its text.');
}

echo "Standalone output contract passed.\n";
