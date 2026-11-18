<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SourceShells;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$toggle = '<a class="nav-link dropdown-toggle%s" data-bs-toggle="collapse">About</a>';
$resting = '<header><nav><ul><li>' . sprintf($toggle, '') . '</li></ul></nav></header>';
$current = str_replace('dropdown-toggle"', 'dropdown-toggle active"', $resting);
$assert(SourceShells::identity($resting) === SourceShells::identity($current), 'A lone navigation toggle needs no sibling or computed colour to neutralize current state.');
$assert(!str_contains(SourceShells::identity($current, false), ' active'), 'The canonical source copy does not freeze the current toggle.');
$assert(str_contains(SourceShells::identity('<section>' . sprintf($toggle, ' active') . '</section>'), ' active'), 'Selected content outside navigation remains structural.');

// Issue #6637: these collapse toggles are anchors without href. Inherited
// colour must not make their route-current underline a different shell design.
foreach (array('', '.navbar a{color:#fff}') as $color) {
    $files = array();
    foreach (array('index' => 'Home', 'about' => 'About', 'focus' => 'Focus') as $current => $title) {
        $items = '';
        foreach (array('about' => 'About', 'focus' => 'Focus') as $slug => $label) {
            $id = 'about' === $slug ? '1' : '2';
            $items .= '<li class="nav-item"><a class="nav-link dropdown-toggle' . ($current === $slug ? ' active' : '') . '" id="dropdown-' . $id . '" data-bs-target="#collapse-' . $id . '" data-bs-toggle="collapse" aria-haspopup="true" aria-expanded="false">' . $label . '</a>'
                . '<div class="collapse" id="collapse-' . $id . '"><ul><li><a class="nav-link" href="/' . $slug . '/">' . ('about' === $slug ? 'About us' : 'Our focus') . '</a></li></ul></div></li>';
        }
        $files[('index' === $current ? 'index' : $current . '/index') . '.html'] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . ' | Acme</title><style>.navbar{display:flex;gap:2rem}.navbar-nav{display:flex;gap:1rem;list-style:none}.nav-link.active{text-decoration:underline}.dropdown-menu{display:none}' . $color . '</style></head><body>'
            . '<header class="site-header"><nav class="navbar"><a class="brand" href="/">Acme</a><ul class="navbar-nav">' . $items . '</ul></nav></header>'
            . '<main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main><footer><p>© Acme</p></footer></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), 'Collapse toggles share one header regardless of explicit link colour.');
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        $template = ThemeTemplates::rendered($plan)[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page')] ?? '';
        $assert(str_contains($template . $markup, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), $page['source_path'] . ' references the shared header.');
        $assert(!str_contains($markup, '<header') && !str_contains($markup, 'dropdown-toggle'), $page['source_path'] . ' keeps no header in post content.');
    }
}
echo "collapse-toggle-current-shell contract passed\n";
