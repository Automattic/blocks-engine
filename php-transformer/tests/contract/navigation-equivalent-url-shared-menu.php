<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\NavigationUrl;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array(
    'HTTPS://CTF.Example.COM:443' => 'https://ctf.example.com/',
    'HTTP://Example.COM:80?Q=%2f#Frag' => 'http://example.com/?Q=%2f#Frag',
    'https://Example.COM:443/Case/%2f?q=A+B#Frag' => 'https://example.com/Case/%2f?q=A+B#Frag',
    'https://Example.COM:8443?#' => 'https://example.com:8443/?#',
    'FTP://Example.COM:21' => 'ftp://example.com/',
    'WS://Example.COM:80' => 'ws://example.com/',
    'WSS://Example.COM:443' => 'wss://example.com/',
    'file:///' => 'file:///',
    'https://user:Pass@Example.COM:443' => 'https://user:Pass@example.com/',
    'https://[::1]:443' => 'https://[::1]/',
    'mailto:User@Example.COM' => 'mailto:User@Example.COM',
    '//Example.COM' => '//Example.COM',
    '../about.html?Q=1#Frag' => '../about.html?Q=1#Frag',
    '{{wordpress-site-plan:route:home}}' => '{{wordpress-site-plan:route:home}}',
) as $authored => $serialized) {
    $assert($serialized === NavigationUrl::serialize($authored), 'Navigation URL serialization: ' . $authored);
    $assert($serialized === NavigationUrl::serialize($serialized), 'Serialization is idempotent: ' . $serialized);
}
foreach (array(
    array('https://ctf.example.com', 'https://ctf.example.com/', 'https://ctf.example.com/'),
    array('HTTPS://CTF.Example.COM:443', 'https://ctf.example.com/', 'https://ctf.example.com/'),
    array('https://CTF.Example.COM:443/Case/%2f?Q=A+B#Frag', 'https://ctf.example.com/Case/%2f?Q=A+B#Frag', 'https://ctf.example.com/Case/%2f?Q=A+B#Frag'),
) as [$first, $second, $expected]) {
$files = array();
foreach (array('index' => $first, 'about' => $second) as $slug => $url) {
    $files[$slug . '.html'] = '<html><head><title>' . $slug . '</title><style>nav a{color:#123456;display:block;padding:8px}nav li{list-style:none}</style></head><body><header><nav><ul>'
        . '<li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li>'
        . '<li><a href="' . $url . '">CTF</a></li></ul></nav></header>'
        . '<main><h1>' . $slug . '</h1><p>Content for ' . $slug . '</p></main></body></html>';
}
foreach (array_keys($files) as $entrypoint) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => $entrypoint, 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $assert(1 === count($plan['menus']), 'Equivalent absolute URLs must share one wp_navigation entity.');
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($headers), 'Equivalent absolute URLs must share one header part.');
    $assert(str_contains($plan['menus'][0]['block_markup'], '"url":"' . $expected . '"'), 'Menu emission uses the serialized URL.');
    $assert(str_contains($headers[0]['canonical_block_markup'], '{{wordpress-site-plan:navigation:' . $plan['menus'][0]['token'] . '}}'), 'The header references the shared menu.');
    $assert(2 === count($plan['pages']), 'Both routes are retained.');
    foreach ($plan['pages'] as $page) {
        $markup = $page['canonical_block_markup'];
        $template = array_column($plan['templates'], 'canonical_block_markup', 'slug')[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page')] ?? '';
        $assert(str_contains($template . $markup, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), 'Both routes reference the shared header.');
        $assert(!str_contains($markup, '<header') && !str_contains($markup, 'wp:navigation '), 'Neither route embeds an inline header or menu.');
    }
}
}
echo "navigation-equivalent-url-shared-menu: ok\n";
