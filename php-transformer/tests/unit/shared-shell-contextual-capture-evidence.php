<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Shared source identity must retain evidence observed only on a non-entry
// route even when the entry route seeds the site's shell identity.
$evidence = htmlspecialchars((string) json_encode(array('attributes' => array('data-motion-enter' => array('before' => null, 'after' => 'done')))), ENT_QUOTES);
$files = array();
foreach (array('index.html', 'about.html', 'team.html') as $i => $path) {
    $capture = 0 === $i ? '' : ' data-motion-enter="done" data-blocks-engine-capture-viewport-entrance="' . $evidence . '"';
    $files[$path] = '<html><head><title>Route ' . $i . '</title><style>main + footer p{padding:23px;color:#123456}</style><script>document.querySelectorAll("[data-blocks-engine-capture-viewport-entrance]").forEach(function(el){var c=JSON.parse(el.getAttribute("data-blocks-engine-capture-viewport-entrance"));el.setAttribute("data-motion-enter",c.attributes["data-motion-enter"].after);});</script></head><body><main><h1>Route ' . $i . '</h1></main><footer><div' . $capture . '><p>Contact us</p></div></footer></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['area']));
if (1 !== count($parts)) throw new RuntimeException('Expected one shared footer.');
$markup = $parts[0]['canonical_block_markup'];
if (!str_contains($markup, 'data-motion-enter="done"') || !str_contains($markup, 'data-blocks-engine-capture-viewport-entrance')) throw new RuntimeException('Non-entry capture evidence was lost.');
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<footer')) throw new RuntimeException('Shared footer remained page-owned.');
}
echo "Shared shell contextual capture evidence passed.\n";
