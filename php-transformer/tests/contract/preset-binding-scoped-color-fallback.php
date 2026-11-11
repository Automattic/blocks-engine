<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach (array('scoped', 'undefined') as $case) {
    $css = ':root{--color_15:47,46,46}'
        . ('scoped' === $case ? '.menu{--txt:var(--color_15)}.card{--txt:255,255,255}' : '')
        . '.copy{color:rgb(var(--color_15))}'
        . '.uniform{color:rgb(var(--txt,var(--color_15)))}'
        . '.item{color:rgb(var(--txt,var(--color_15)))}';
    $html = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
        . str_repeat('<p class="copy">Copy</p>', 6)
        . '<nav class="menu"><a class="item uniform" href="/one">One</a><a class="item uniform" href="/two">Two</a></nav>'
        . '<div class="card"><a class="item" href="/three">Three</a></div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'site.css' => $css)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $palette = array();
    foreach ($plan['writes'] as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $palette = array_column($theme['settings']['color']['palette'], 'slug', 'color');
    }
    $slug = $palette['#2f2e2e'] ?? null;
    $assert(is_string($slug), $case . ': rendered copy supplies an exact palette token.');
    $output = implode("\n", array_column($plan['assets'], 'content'));
    $fallback = 'rgb(var(--txt,var(--color_15)))';
    $bound = 'color:var(--wp--preset--color--' . $slug . ',' . $fallback . ')';
    $assert(str_contains($output, 'color:var(--wp--preset--color--' . $slug . ',rgb(var(--color_15)))'), $case . ': root-resolved binding is unchanged.');
    $assert(1 === preg_match('/\.uniform[^{}]*\{[^{}]*' . preg_quote($bound, '/') . '/', $output), $case . ': uniformly rendered rule binds with the original fallback.');
    $item = 'scoped' === $case ? 'color:' . $fallback : $bound;
    $assert(1 === preg_match('/\.item[^{}]*\{[^{}]*' . preg_quote($item, '/') . '/', $output), $case . ': mixed colours stay literal; an undefined custom property uses rendered evidence.');
    echo $case . ": uniform bound; mixed/undefined rule correct; root binding unchanged.\n";
}

echo "Scoped colour fallback binding contract passed.\n";
