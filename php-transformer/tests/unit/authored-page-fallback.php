<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Captured pages have route-specific frames. A new page instead uses page.html:
// it must not lose its title merely because no captured route uses that fallback.
$files = array();
foreach (array('index.html' => 'Home', 'faqs/location/index.html' => 'Location') as $path => $title) {
    $files[$path] = '<html><head><title>' . $title . '</title></head><body><header><a href="/">Site</a></header><main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main><footer>Copyright</footer></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$fallback = $templates['page'] ?? '';
if (1 !== substr_count($fallback, '<!-- wp:post-title {"level":1} /-->') || !str_contains($fallback, '"type":"constrained"') || 1 !== substr_count($fallback, '<!-- wp:post-content /-->')) {
    throw new RuntimeException('New pages need a titled, constrained native fallback. Got: ' . $fallback);
}
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<!-- wp:post-title')) {
        throw new RuntimeException('Fallback title must not be injected into captured page content.');
    }
}
echo "authored-page-fallback: passed\n";
