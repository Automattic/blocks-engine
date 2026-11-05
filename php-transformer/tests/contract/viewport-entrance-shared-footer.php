<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$evidence = htmlspecialchars((string) json_encode(array('attributes' => array('data-motion-enter' => array('before' => null, 'after' => 'done')))), ENT_QUOTES);
$files = array();
// The entry route is short and never triggered the entrance. It must not seed
// an evidence-free shared part when other routes observed the animation.
foreach (array('index.html', 'about.html', 'team.html') as $index => $path) {
    $entrance = 0 === $index ? '' : ' data-motion-enter="done" data-blocks-engine-capture-viewport-entrance="' . $evidence . '"';
    $files[$path] = '<!doctype html><html><head><title>Site</title><script>document.querySelectorAll("[data-blocks-engine-capture-viewport-entrance]").forEach(function(el){var c=JSON.parse(el.getAttribute("data-blocks-engine-capture-viewport-entrance"));el.setAttribute("data-motion-enter",c.attributes["data-motion-enter"].after);});</script></head><body><div id="app"><main><h1>Route ' . $index . '</h1></main><footer id="footer"><div id="reveal"' . $entrance . '>Shared footer</div></footer></div></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$assert(isset($result['source_reports']['wordpress_site_plan']), 'A site plan is produced: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)));
$assert(1 === count($footers), 'Exactly one footer part is extracted.');
$footer = $footers[0];
$sources = $footer['placement']['source_paths'] ?? array();
sort($sources);
$assert(array('about.html', 'index.html', 'team.html') === $sources, 'All three routes share the footer.');
$markup = $footer['canonical_block_markup'];
$assert(str_contains($markup, 'data-blocks-engine-capture-viewport-entrance=') && str_contains($markup, 'data-motion-enter="done"'), 'The shared part retains observed entrance evidence and its after state.');
foreach ($plan['templates'] as $template) {
    $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $footer['slug'] . '"'), 'Every route template binds the shared footer.');
}
fwrite(STDOUT, "Viewport entrance shared footer contract passed.\n");
