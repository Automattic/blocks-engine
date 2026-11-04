<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$template = (string) file_get_contents(dirname(__DIR__) . '/fixtures/navbar-toggler-current-item/page.template.html');
// Optional state-only rule that applies only to a current item below the collapse boundary.
$stateRule = '@media(max-width:1023.98px){.nav-item.active .dropdown-menu{display:block}}';
$routes = array(
    'index.html'           => array('Home', array()),
    'about/index.html'     => array('About', array('about')),
    'about/team/index.html' => array('Team', array('about', 'team')),
    'info/dogs/index.html' => array('Dogs', array('info', 'dogs')),
);
// The second variant adds the menu-only collapse boundary: the brand sits outside the
// navigation, the collapse panel switches at 1024px, and a current item adds a
// fractional state-only candidate (1023.98px) beside the shared boundary.
$boundary = str_replace(
    array(
        '<nav class="navbar"><a class="navbar-brand" href="/">Fund</a>',
        '.dropdown-menu{display:none;',
        '@media(max-width:991px){.navbar-toggler{display:block}.collapse:not(.show){display:none}}',
    ),
    array(
        '<a class="navbar-brand" href="/">Fund</a><nav class="navbar">',
        '.dropdown-menu{',
        '.collapse:not(.show){display:none}@media(min-width:1024px){.collapse:not(.show){display:block}.navbar-toggler{display:none}}@media(max-width:1023px){.navbar-toggler{display:block}}' . $stateRule,
    ),
    $template
);
foreach (array('toggler' => $template, 'boundary' => $boundary) as $label => $source) {
    $files = array();
    foreach ($routes as $path => [$title, $active]) {
        $replace = array('{{title}}' => $title);
        foreach (array('about', 'team', 'info', 'dogs') as $key) $replace['{{' . $key . '}}'] = in_array($key, $active, true) ? ' active' : '';
        $files[$path] = strtr($source, $replace);
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($headers), $label . ': current-item classes inside a toggler panel must not fork the header (' . count($headers) . ' header parts).');
    $assert(1 === count($plan['menus']), $label . ': one shared navigation entity.');
    foreach ($plan['pages'] as $page) {
        $assert(!str_contains($page['canonical_block_markup'], 'wp:navigation '), $label . ': no page keeps an inline header: ' . ($page['path'] ?? $page['slug'] ?? ''));
    }
}
echo "ok\n";
