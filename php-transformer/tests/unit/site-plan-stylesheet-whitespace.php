<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><style>.paint { text-shadow: 1px 2px red; } .paint:hover { text-shadow: 2px 3px blue; }</style></head><body><main><h1 class="paint">Hello</h1><p>World</p></main></body></html>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$count = 0;
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
    ++$count;
    if ($asset['content'] !== CssSyntaxScanner::withoutRedundantWhitespace($asset['content'])) {
        throw new RuntimeException('Final theme CSS contains redundant whitespace: ' . ($asset['target_path'] ?? $asset['id'] ?? 'stylesheet'));
    }
}
if (!$count) throw new RuntimeException('Expected generated theme CSS');
echo "Site plan stylesheets omit redundant whitespace\n";
