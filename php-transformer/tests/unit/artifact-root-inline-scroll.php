<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/root-inline-scroll/page.html');
$compile = static fn(string $source): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'site/index.html', 'files' => array('site/index.html' => $source)))->toArray()['source_reports']['wordpress_site_plan'];
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$plan = $compile($html);
$class = DocumentRootContext::routeClass(WordPressSitePlan::identity('page', 'site/index.html', 'site/index.html'));
$frontend = $editor = '';
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    if ('frontend' === ($asset['stylesheet_target'] ?? 'both')) $frontend .= $asset['content'] ?? '';
    else $editor .= $asset['content'] ?? '';
}
$assert(str_contains($frontend, 'html.' . $class . ':is('), 'Root carrier must be route-scoped with inline-equivalent weight');
preg_match_all('/([^{}]+)\{([^{}]+)\}/', $frontend, $rules, PREG_SET_ORDER);
$seen = array();
foreach ($rules as $rule) {
    $tag = str_ends_with(trim($rule[1]), ' body') ? 'body' : 'html';
    $assert(str_contains($rule[1], 'html.' . $class), 'Every rule must be route-scoped');
    $assert(str_contains($rule[2], 'position: static !important'), 'Inline position priority must survive');
    $assert(str_contains($rule[2], 'height: auto !important'), 'Inline height priority must survive');
    $assert(str_contains($rule[2], 'overflow: hidden auto' . ('html' === $tag ? ' !important' : ';')), 'Overflow priority must remain authored');
    $seen[$tag] = true;
}
$assert(isset($seen['html'], $seen['body']), 'Both document elements must retain inline styles');
$assert(!str_contains($editor, 'position: static !important') && !str_contains($editor, 'overflow: hidden auto'), 'Editor styles must not receive inline document mechanics');
$assert(str_contains($editor, ':root body{overflow:auto!important;height:auto!important;'), 'Editor scroll repair must remain unconditional');
foreach (array('{}', '&lt;', '&gt;') as $unsafe) {
    $hostile = $compile(str_replace('position: static !important;', 'position: static !important;--unsafe:' . $unsafe . ';', $html));
    foreach ($hostile['assets'] as $asset) {
        $assert('frontend' !== ($asset['stylesheet_target'] ?? 'both'), 'Unsafe document styles must be rejected');
    }
}
echo "artifact root inline scroll passed\n";
