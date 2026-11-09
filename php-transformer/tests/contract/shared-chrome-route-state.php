<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EntityEquivalence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/*
 * A site's chrome captured on several routes carries route state that is not
 * part of the chrome: the home route's in-page links resolve to fragment
 * targets the capture placed inside the header, a scroll script left a state
 * class on the home header that no rule renders at rest, and the responsive
 * capture hooked an element whose two viewport styles differed only in
 * spelling. The chrome still renders the same on every route, so every route
 * binds the same parts and the home route keeps its fragment targets.
 */
$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

$hook = 'data-blocks-engine-capture-responsive-0a1b2c3d4e5f';
$css = '.bar{display:flex;gap:12px;background:#123}.bar.is-pinned{transition-delay:0s}.bar.is-pinned.is-revealed{visibility:visible}.signup-row{gap:8px}.consent-box{width:18px;height:18px;accent-color:#123}.join{background:#123;color:#fff}';
$header = static function (string $state, string $targets): string {
    return '<header id="site-top"><section id="site-top-bar" class="bar' . $state . '">' . $targets . '<p class="brand">Brand</p><nav><a href="/">Home</a><a href="/about/">About</a><a href="/#signup">Sign up</a></nav></section></header>';
};
$target = static fn (string $id): string => '<span id="' . $id . '" data-blocks-engine-capture-anchor-target="' . $id . '" data-blocks-engine-capture-anchor-source-id="site-top-bar" aria-hidden="true" style="position: absolute;overflow: hidden;pointer-events: none;width: 0px;height: 0px"></span>';
$footer = static function (string $rowClass, string $rowStyle, string $labelStyle = 'font-weight:700;color:#123'): string {
    return '<footer id="site-bottom"><section class="closing"><h2>Stay in touch</h2><div class="' . $rowClass . '" style="' . $rowStyle . '"><p>Monthly notes</p><p>No spam</p></div><form aria-label="Notes"><label class="consent-label"><input type="checkbox" class="consent-box" name="consent" required> <span class="consent" style="' . $labelStyle . '">Yes, send me notes.</span></label><button type="button" class="join">Join</button></form><p>&copy; Example</p></section></footer>';
};
$page = static fn (string $head, string $header, string $main, string $footer): string => '<!doctype html><html><head><style>' . $head . '</style></head><body>' . $header . '<main>' . $main . '</main>' . $footer . '</body></html>';
$inner = static fn (string $title, string $body): string => $page($css . '@media(max-width:600px){.' . $hook . '{display:grid!important;width: 100%!important}}', $header('', ''), '<h1>' . $title . '</h1><p>' . $body . '</p>', $footer('signup-row ' . $hook, 'display:grid;width:100%'));
$home = $page($css, $header(' is-pinned', $target('signup') . $target('features')), '<h1>Welcome</h1><p><a href="#signup">Join the list</a></p><section><h2>Features</h2><p><a href="#features">Read more</a></p></section>', $footer('signup-row', 'display: grid;width: 100%', 'color: #123;font-weight: 700'));

$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$plan = $compile(array(
    'index.html' => $home,
    'about/index.html' => $inner('About', 'Who we are.'),
    'contact/index.html' => $inner('Contact', 'Write to us.'),
));
$templates = WordPressSitePlan::renderedTemplates($plan);
$pages = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
$extracted = array();
foreach ($plan['diagnostics'] as $diagnostic) if ('wordpress_site_plan_shell_extracted' === ($diagnostic['code'] ?? null)) $extracted[$diagnostic['area']] = $diagnostic;
foreach (array('header', 'footer') as $area) {
    $assert(3 === ($extracted[$area]['page_count'] ?? null) && array() === ($extracted[$area]['exclusions'] ?? null), "The {$area} joins one shared part on every route, the home route included: " . json_encode($extracted[$area] ?? null));
    $assert(1 === preg_match_all('/<!-- wp:template-part \{[^}]*"area":"' . $area . '"/', $templates['front-page'] ?? ''), "The front-page template binds the shared {$area} part.");
}
foreach ($pages as $source => $markup) {
    $assert(!str_contains($markup, 'Brand') && !str_contains($markup, 'Stay in touch') && !str_contains($markup, 'is-pinned'), "{$source} keeps no inline chrome: {$markup}");
}
$parts = implode('', array_column($plan['template_parts'], 'canonical_block_markup'));
$assert(!str_contains($parts, 'is-pinned') && !str_contains($parts, '"anchor":"signup"') && !str_contains($parts, $hook), 'Shared parts carry neither route state nor a capture hook that only restates inline style.');
$homeDocument = ($templates['front-page'] ?? '') . ($pages['index.html'] ?? '');
foreach (array('signup', 'features') as $fragment) {
    $assert(1 === substr_count($homeDocument, '"anchor":"' . $fragment . '"'), "The home route keeps its #{$fragment} fragment target outside the shared header.");
    foreach (array('about/index.html', 'contact/index.html') as $source) $assert(!str_contains($pages[$source], '"anchor":"' . $fragment . '"'), "{$source} does not gain the home route's #{$fragment} target.");
}

// Each route's own layout places the shared footer: the same rule names the
// footer on every route but declares that route's grid row. One global rule
// cannot hold every placement, so each route keeps its own.
$placed = static fn (int $row, string $title): string => $page($css . '.page{display:grid}#site-bottom{grid-row:' . $row . ';background:#eee}', $header('', ''), '<h1>' . $title . '</h1>', $footer('signup-row', 'display:grid;width:100%'));
$placement = $compile(array(
    'index.html' => $placed(9, 'Welcome'),
    'about/index.html' => $placed(2, 'About'),
    'contact/index.html' => $placed(3, 'Contact'),
));
$footerShell = current(array_filter($placement['diagnostics'], static fn (array $row): bool => 'wordpress_site_plan_shell_extracted' === ($row['code'] ?? null) && 'footer' === ($row['area'] ?? null)));
$assert(3 === ($footerShell['page_count'] ?? null), 'A footer placed differently by each route still joins one shared part: ' . json_encode($footerShell));
foreach (array('index.html' => 9, 'about/index.html' => 2, 'contact/index.html' => 3) as $source => $row) {
    foreach ($placement['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? null) || !preg_match('/grid-row:\s*' . $row . '\b/', (string) ($asset['content'] ?? ''))) continue;
        $scopes = array_column($asset['scopes'] ?? array(), 'source_path');
        $assert(array($source) === $scopes, "Only {$source} applies its footer placement (row {$row}): " . json_encode($asset['scopes'] ?? null));
    }
}

// A state class some author rule paints at rest is the chrome's own variant;
// a hook whose rule sets more than the inline style is a width-scoped
// presentation. Either difference keeps the home chrome page-owned.
$painted = $compile(array(
    'index.html' => $page($css . '.bar.is-pinned{background:#900}', $header(' is-pinned', ''), '<h1>Welcome</h1>', $footer('signup-row', 'display:grid;width:100%')),
    'about/index.html' => $page($css, $header('', ''), '<h1>About</h1>', $footer('signup-row', 'display:grid;width:100%')),
    'contact/index.html' => $page($css, $header('', ''), '<h1>Contact</h1>', $footer('signup-row', 'display:grid;width:100%')),
));
$responsive = static fn (string $title): string => $page($css . '@media(max-width:600px){.' . $hook . '{display:block!important;width: 100%!important}}', $header('', ''), '<h1>' . $title . '</h1>', $footer('signup-row ' . $hook, 'display:grid;width:100%'));
$widthScoped = $compile(array(
    'index.html' => $page($css, $header('', ''), '<h1>Welcome</h1>', $footer('signup-row', 'display:grid;width:100%')),
    'about/index.html' => $responsive('About'),
    'contact/index.html' => $responsive('Contact'),
));
foreach (array('header' => $painted, 'footer' => $widthScoped) as $area => $negative) {
    $diagnostic = current(array_filter($negative['diagnostics'], static fn (array $row): bool => 'wordpress_site_plan_shell_extracted' === ($row['code'] ?? null) && $area === ($row['area'] ?? null)));
    $excluded = array_column($diagnostic['exclusions'] ?? array(), 'reason', 'source_path');
    $assert(2 === ($diagnostic['page_count'] ?? null) && 'non_equivalent' === ($excluded['index.html'] ?? null), "A home {$area} that renders differently stays page-owned: " . json_encode($diagnostic));
    $assert(str_contains(array_column($negative['pages'], 'canonical_block_markup', 'source_path')['index.html'] ?? '', 'header' === $area ? 'Brand' : 'Stay in touch'), "The differing home {$area} keeps its inline chrome.");
}

// A runtime entity's source contract losses report its page's stylesheet set.
// Two captures of the same form on routes with different stylesheets are the
// same entity.
$form = array('form' => array('id' => 'newsletter'), 'controls' => array(array('type' => 'email', 'label' => 'Email')), 'bindings' => array(array('role' => 'form', 'source_path' => 'index.html')));
$assert(EntityEquivalence::key($form + array('source_contract_losses' => array('unsupported_selector:.a:hover')), 'forms') === EntityEquivalence::key($form + array('source_contract_losses' => array('unsupported_selector:.b:focus')), 'forms'), 'Source contract losses do not split equivalent form entities.');
$assert(EntityEquivalence::key($form, 'forms') !== EntityEquivalence::key(array('controls' => array(array('type' => 'text', 'label' => 'Name'))) + $form, 'forms'), 'Forms with different controls remain distinct entities.');

echo "shared chrome route state contract passed\n";
