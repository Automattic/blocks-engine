<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Route-specific rich-text contexts used to create overlapping chrome slices,
// even though current-item state had already been removed from the shared part.
$css = 'header .sr-only{position:absolute;width:1px;height:1px;overflow:hidden}'
    . '.cta{border:1px solid;opacity:.8}.cta:hover{opacity:.9}.cta{opacity:.7}'
    . '@media (max-width:700px){.cta{border-width:2px}.cta:hover{opacity:.6}}'
    . '.home{padding:2em}.post{padding:1em}.legal{padding:0}.note{margin:0}';
$routes = array('index' => 'home', 'a' => 'post', 'b' => 'legal', 'c' => 'note');
$files = array('site.css' => $css);
foreach ($routes as $slug => $context) {
    $nav = '';
    foreach ($routes as $target => $_) {
        $nav .= '<a href="' . $target . '.html"' . ($target === $slug ? ' aria-current="page"' : '') . '>' . $target . '</a>';
    }
    $files[$slug . '.html'] = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body>'
        . '<header><a href="index.html">Acme</a><nav>' . $nav . '</nav>'
        . '<a class="cta" href="c.html">Start<span class="sr-only"> a project</span></a></header>'
        . '<main class="' . $context . '"><h1>' . $slug . '</h1><p>Route content</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Current-item variants share one header part.');
$assert(!str_contains($headers[0]['canonical_block_markup'], 'blocks-engine-current-navigation-item'), 'The part freezes no current-item state.');
$chrome = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_contains($asset['target_path'], '/shared-chrome-') && 'editor' !== ($asset['stylesheet_target'] ?? 'both')));
$assert(1 === count($chrome), 'One shared header has exactly one frontend chrome stylesheet, got ' . count($chrome));
$target = $chrome[0]['target_path'];
$writes = array_column($plan['writes'], null, 'target_path');
$assert(isset($writes[$target]), 'The chrome resource is materialized.');
$editorTarget = substr($target, 0, -4) . '.editor.css';
$assert(isset($writes[$editorTarget]), 'The shared stylesheet has one editor sibling.');
$chromeWrites = array_filter(array_keys($writes), static fn(string $path): bool => str_contains($path, '/shared-chrome-'));
$assert(2 === count($chromeWrites), 'No former variant or editor sibling is materialized.');

$bootstrap = $writes['functions.php']['payload']['data'];
$frontend = (string) strstr($bootstrap, "add_action( 'wp_enqueue_scripts'");
$frontend = substr($frontend, 0, (int) strpos($frontend, '}, 1 );'));
preg_match_all('/^    if \( (.+?) \) \{\n((?:        [^\n]+\n)+)    \}$/m', $frontend, $blocks, PREG_SET_ORDER);
$assert(count($blocks) >= count($routes), 'Every captured page has an enqueue branch.');
foreach ($blocks as $block) {
    preg_match_all("/wp_enqueue_style\\( '[^']+', get_theme_file_uri\\( '([^']+)' \\)/", $block[2], $calls);
    $enqueued = array_values(array_filter($calls[1], static fn(string $path): bool => str_contains($path, '/shared-chrome-')));
    $assert(array($target) === $enqueued, 'Every page enqueues only the single chrome resource, once: ' . $block[1]);
}

$rules = array();
(new CssStylesheetTransformer())->visitStyleRules($chrome[0]['content'], static function (string $selector, string $body, array $parents) use (&$rules): void {
    $rules[] = json_encode(array($parents, trim($selector), trim($body)));
});
$assert(count($rules) === count(array_unique($rules)), 'No rule is duplicated in the shared stylesheet, including conditional rules.');
// Compare the competing authored rules to the single-route source cascade:
// consolidation must neither sort selectors nor merge declaration bodies.
$competing = static function (string $css): array {
    $result = array();
    (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $parents) use (&$result): void {
        if (in_array(trim($selector), array('.cta', '.cta:hover'), true)) $result[] = array($parents, trim($selector), trim($body));
    });
    return $result;
};
$assert($competing($css) === $competing($chrome[0]['content']), 'Shared chrome keeps the single-route rule order, specificity, declarations and media context.');

echo "shared-part-single-chrome-stylesheet contract passed\n";
