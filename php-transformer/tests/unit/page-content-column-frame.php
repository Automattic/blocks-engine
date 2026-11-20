<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Exercise the public compiler/plan boundary: a newly authored page must
// inherit the same nested column as the captured body, without copying text.
$files = array();
foreach (array('index.html' => 'Home', 'about/index.html' => 'About') as $path => $title) {
    $files[$path] = '<html><head><title>' . $title . '</title><style>'
        . '.column{width:340px;margin:0 auto}.decoration{height:2px;background:red}'
        . '</style></head><body><div class="site"><header><nav><a href="/">Home</a><a href="/about/">About</a></nav></header>'
        . '<main><section><div class="decoration"></div><div class="column"><h1>' . $title . '</h1><p>' . $title . ' body text.</p></div></section></main>'
        . '<footer><p>Shared footer</p></footer></div></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$templates = array_column($plan['templates'], null, 'slug');
$template = $templates['page']['canonical_block_markup'];
if (!str_contains($template, 'column') || !str_contains($template, 'decoration') || !str_contains($template, '<!-- wp:post-content')) {
    throw new RuntimeException('Generic page template must carry the authored body column and its decoration');
}
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], 'class="wp-block-group column"')) {
        throw new RuntimeException('Column must not be duplicated in captured post content');
    }
    if (!str_contains($page['canonical_block_markup'], 'body text.')) throw new RuntimeException('Body text must remain editable post content');
}
if (str_contains($template, 'body text.')) throw new RuntimeException('Template must not copy captured body text');
echo "page-content-column-frame: passed\n";
