<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$page = static function (string $current): string {
    $links = '';
    foreach (array('Home' => '/index.html', 'About' => '/about/index.html', 'Contact' => '/contact/index.html') as $label => $href) {
        $links .= '<a class="item ' . ($current === $label ? 'text-primary' : 'text-muted') . '" href="' . $href . '">' . $label . '</a>';
    }
    return '<html><head><style>header{min-height:' . ('About' === $current ? '50' : '40') . 'px}.text-primary{color:red}.text-muted{color:gray}nav{display:flex;gap:20px}</style></head><body><header><nav>' . $links . '</nav></header><main><h1>' . $current . '</h1></main><footer>Copyright</footer></body></html>';
};
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => $page('Home')),
        array('path' => 'website/about/index.html', 'content' => $page('About')),
        array('path' => 'website/contact/index.html', 'content' => $page('Contact')),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === $part['area']));
if (1 !== count($headers)) throw new RuntimeException('A demonstrated self-target class pair must yield one shared header.');
foreach ($plan['pages'] as $route) {
    if (str_contains($route['canonical_block_markup'], '<header')) throw new RuntimeException('Current-link utility classes must not strand a route header in page content.');
}
foreach ($plan['templates'] as $template) {
    if (!str_contains($template['canonical_block_markup'], '"slug":"' . $headers[0]['slug'] . '"')) throw new RuntimeException('Every lifecycle must reference the shared header.');
}
echo "Shared shell self-target class pair: passed\n";
