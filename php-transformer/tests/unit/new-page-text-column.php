<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// The shell surrounds main, but the authored text column lives inside it.
$files = array();
foreach (array('index.html', 'legal/index.html', 'terms/index.html') as $path) {
    $files[$path] = '<html><head><title>Document</title><style>.text-column{width:53%;margin-left:4%}</style></head><body><div class="site"><header><nav><a href="/">Home</a></nav></header><main class="page-main"><section class="page-section"><div class="text-column"><p>Captured body for ' . $path . '</p></div><div class="decoration"></div></section><section><p>Other content</p></section></main><footer><p>Shared footer</p></footer></div></body></html>';
}
// A distinct inferred route frame must not be replaced by the generic one
// merely because its page record has no explicit route_template field.
$files['about/index.html'] = str_replace('class="site"', 'class="about-frame"', $files['index.html']);
$files['terms/index.html'] = str_replace(array('class="text-column"', '</p></div>'), array('class="body-column"', '</p><p>Second body paragraph</p><p>Third body paragraph</p></div>'), $files['terms/index.html']);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'], 'body-column')) throw new RuntimeException('New pages must inherit the continuous body column, not the first route label');
if (str_contains($templates['page'], 'Captured body')) throw new RuntimeException('The template must not freeze captured text');
if (1 !== substr_count($templates['page'], '<!-- wp:post-content')) throw new RuntimeException('New pages need exactly one content slot');
foreach ($plan['pages'] as $page) {
    if (!empty($page['entrypoint'])) continue;
    $slug = $page['route_template'] ?? 'page';
    if (str_contains($templates[$slug], 'text-column') || str_contains($templates[$slug], 'body-column')) throw new RuntimeException('Captured pages must not receive a second text column');
    if (!str_contains($page['canonical_block_markup'], 'text-column') && !str_contains($page['canonical_block_markup'], 'body-column')) throw new RuntimeException('Captured content must retain its layout');
}
if (!str_contains($templates['page-about'], 'about-frame')) throw new RuntimeException('An inferred route must retain its own captured frame');
if (str_contains($templates['page-about'], 'text-column')) throw new RuntimeException('An inferred route must not inherit the new-page column');
echo "New page text column passed\n";
