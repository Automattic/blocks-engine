<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$files = array();
foreach (array('index', 'about', 'visit') as $route) {
    $links = '';
    // Ten block-wrapping links produce ten layout-shell href copies. The
    // four text anchors below do not: anchor and wrapper counts must differ.
    foreach (array('index', 'about', 'visit', 'contact', 'news', 'team', 'jobs', 'help', 'shop', 'events') as $target) {
        $links .= '<a class="menu-link' . ($target === $route ? ' selected' : '') . '" href="' . (in_array($target, array('index', 'about', 'visit'), true) ? $target . '.html' : 'https://example.test/' . $target . '/') . '"' . ($target === $route ? ' aria-current="page"' : '') . '><p>' . ucfirst($target) . '</p></a>';
    }
    $links .= '<a class="lang-link selected" aria-current="page" href="' . $route . '.html">English</a><a class="lang-link" href="https://example.test/fr/' . $route . '.html">Français</a>';
    // A fixed href that is self on only one route must remain fixed everywhere.
    $links .= '<a class="text-link' . ('about' === $route ? ' selected' : '') . '" href="about.html"' . ('about' === $route ? ' aria-current="page"' : '') . '>About us</a><a class="text-link" href="mailto:office@example.test">Email</a>';
    $files[$route . '.html'] = '<!doctype html><html><body><div class="navbar"><p class="brand">Harbor studio</p><div class="menu-items"><p>Explore our studio</p>' . $links . '</div></div><main><h1>' . $route . '</h1></main><footer><p>Shared address</p></footer></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? throw new RuntimeException(json_encode($result['diagnostics']));
WordPressSitePlan::assertValid($plan);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers) && 'header' === $headers[0]['slug'], 'Mixed plain and block-wrapping links must yield one parts/header.html.');
$header = $headers[0]['canonical_block_markup'];
$assert(14 === preg_match_all('/<a\\b/', $header) && 10 === substr_count($header, '"href":"'), 'The fixture exercises fourteen anchors but only ten wrapper hrefs.');
$assert(str_contains($header, 'fr/index.html') && str_contains($header, 'href="/about"'), 'The part retains the entry route counterpart and the fixed route link: ' . $header);
$assert(!str_contains($header, 'aria-current') && !str_contains($header, 'selected'), 'Plain-link current markers are not frozen into the part.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], 'navbar'), 'No route retains the navbar in content.');
fwrite(STDOUT, "route-edge plain-link state contract passed\n");
