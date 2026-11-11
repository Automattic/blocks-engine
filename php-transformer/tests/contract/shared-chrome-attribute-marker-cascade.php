<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Issue #5428: author attribute selectors are lowered onto generated marker
// classes. A shared footer and a route section both carry the base
// `.container[data-v]` marker; only the section adds the equal-specificity
// `.container--flush[data-v]` override. Shared-chrome projection must not move
// the base marker rules into the shared-chrome stylesheet (enqueued after the
// route stylesheet) while the override stays on the route: on every route the
// override must still follow the base rules, so the flush section has no padding.
$css = 'body{margin:0}.site-header{padding:16px}.container{padding:0 24px}'
    . '.container[data-v-1]{padding-bottom:32px;padding-top:32px}'
    . '@media (min-width:600px){.container[data-v-1]{padding-bottom:48px;padding-top:48px}}'
    . '.container--flush[data-v-1]{padding-bottom:0;padding-top:0}'
    . '.site-footer{border-top:1px solid #000}';
$document = static fn (string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="/site.css"></head><body>'
    . '<header class="site-header"><a href="/">Acme</a> <a href="/about/">About</a></header>'
    . '<main><section class="band"><div data-v-1="" class="container container--flush"><p>Welcome ' . $title . '</p></div></section></main>'
    . '<footer class="site-footer"><div data-v-1="" class="container"><p>Footer</p></div></footer>'
    . '</body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'index.html', 'content' => $document('Home')),
        array('path' => 'about/index.html', 'content' => $document('About')),
        array('path' => 'site.css', 'content' => $css),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$assert(array() !== array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'footer' === ($part['area'] ?? null) && 'shared_shell' === ($part['placement']['kind'] ?? null)), 'The footer is a shared template part.');

foreach (array('index.html', 'about/index.html') as $route) {
    $enqueued = array();
    foreach ($plan['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
            if ($route === ($instance['source_path'] ?? null)) $enqueued[(int) $instance['order']] = (string) ($asset['content'] ?? '');
        }
    }
    ksort($enqueued);
    $effective = implode("\n", $enqueued);
    $base = max((int) strrpos($effective, 'padding-top:32px'), (int) strrpos($effective, 'padding-top:48px'));
    $flush = strrpos($effective, 'padding-top:0}');
    $assert($base > 0 && false !== $flush, 'On ' . $route . ' both the base and the flush rules are enqueued: ' . json_encode($enqueued));
    $assert($flush > $base, 'On ' . $route . ' the flush override follows the base attribute rules in enqueue order: ' . json_encode($enqueued));
}

echo "Shared chrome attribute marker cascade contract passed.\n";
