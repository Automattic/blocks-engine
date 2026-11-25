<?php
/**
 * Atomic-CSS site builders emit per-page utility classes: one class name can
 * hide content in one page's stylesheet and size it in another's. When such a
 * class sits in a hoisted footer and in route content, each stylesheet's rule
 * reaching it through the footer's former ancestors (`.x .u-wide`) lifts a
 * re-anchored copy into the global shared chrome stylesheet. That copy keeps
 * the `.x` arm, so without a route gate one page's `display:none` hides every
 * other page's content. Each copy stays gated to the routes loading its sheet.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;

$page = static function (string $title, string $sheet, string $main): string {
    $documents = '';
    foreach (array('desktop', 'mobile') as $device) {
        $documents .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope=""><div class="x">'
            . '<main>' . $main . '</main>'
            . '<div class="site-footer" data-aid="FOOTER_SECTION"><div class="u-wide"><p>&copy; Studio</p></div></div>'
            . '</div></div>';
    }
    return '<!doctype html><html><head><title>' . $title . '</title><style>body{margin:0}.site-footer{background:#eee;padding:40px}'
        . '@media(max-width:600px){.data-blocks-engine-capture-desktop-document{display:none}}@media(min-width:601px){.data-blocks-engine-capture-mobile-document{display:none}}</style>'
        . '<link rel="stylesheet" href="/' . $sheet . '"></head><body>' . $documents . '</body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', 'assets/wide.css', '<section class="u-wide"><h1>Welcome</h1><p>About us.</p></section>'),
    'notes/index.html' => $page('Notes', 'assets/wide.css', '<section class="u-wide"><h1>Notes</h1><p>Latest notes.</p></section>'),
    'news/index.html' => $page('News', 'assets/wide.css', '<section class="u-wide"><h1>News</h1><p>Latest news.</p></section>'),
    'account/index.html' => $page('Account', 'assets/account.css', '<section><h1>Sign in</h1><p>Enter your email.</p></section>'),
    'assets/wide.css' => '.x .u-wide{max-width:100%}',
    'assets/account.css' => '.x .u-wide{display:none}',
)))->toArray()['source_reports']['wordpress_site_plan'];

$failures = array();
$footer = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
if ('shared_shell' !== ($footer['placement']['kind'] ?? null) || !in_array('x', (array) ($footer['ancestor_context']['classes'] ?? array()), true)) $failures[] = 'expected one footer hoisted out of `.x`.';
if (!str_contains((string) ($footer['canonical_block_markup'] ?? ''), 'u-wide')) $failures[] = 'the hoisted footer no longer carries `.u-wide`.';

$routeClass = array();
foreach ($plan['pages'] as $document) $routeClass[(string) $document['source_path']] = (string) DocumentRootContext::stampedRouteClass($document);
$shared = '';
foreach ($plan['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && str_starts_with((string) ($asset['path'] ?? ''), 'assets/css/shared-chrome-')) $shared .= (string) $asset['content'];
}
// Each sheet's copy reaches the hoisted footer only on the routes loading it.
$rule = static function (string $declaration) use ($shared): ?string {
    return preg_match('/(?:^|})([^{}]*\.u-wide)\{' . preg_quote($declaration, '/') . '\}/', $shared, $match) ? $match[1] : null;
};
$hidden = $rule('display:none');
$sized = $rule('max-width:100%');
if (null === $hidden || null === $sized) {
    $failures[] = 'shared chrome lost the footer copies of `.x .u-wide`: ' . $shared;
} else {
    if (!str_starts_with($hidden, ':where(:root:is(.' . $routeClass['account/index.html'] . ')) ')) $failures[] = 'the account sheet\'s `display:none` copy is not gated to the account route: ' . $hidden;
    $wide = array($routeClass['index.html'], $routeClass['notes/index.html'], $routeClass['news/index.html']);
    sort($wide);
    if (!str_starts_with($sized, ':where(:root:is(.' . implode(',.', $wide) . ')) ')) $failures[] = 'the shared sheet\'s `max-width` copy is not gated to its routes: ' . $sized;
    if (!str_contains($hidden, ':has(> ')) $failures[] = 'the copy no longer reaches the hoisted footer: ' . $hidden;
}

if (array() !== $failures) {
    fwrite(STDERR, "shared chrome route-local hook copy contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "shared chrome route-local hook copy contract passed\n";
