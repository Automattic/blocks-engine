<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Every page shares the captured footer, but one page loads its footer rule
// from its own stylesheet (as a route captured without a tablet document does).
// The stylesheet the other pages share must still lay out the shared footer,
// resolved from the site root rather than each page's directory.
$document = static fn(string $device, string $title): string => '<div data-blocks-engine-capture-device-document="' . $device . '"><main><h1>' . $title . '</h1></main><footer id="site-footer"><div id="footer-grid"><p>One</p><p>Two</p></div></footer></div>';
$page = static fn(string $css, string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="/base.css"><link rel="stylesheet" href="/' . $css . '"></head><body><div id="site-root">' . $document('desktop', $title) . $document('mobile', $title) . '</div></body></html>';
$rule = ':where([data-blocks-engine-capture-device-document="desktop"]) #footer-grid{display:grid;grid-template-columns:1fr 1fr}';
$compiled = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'site/index.html',
    'files'      => array(
        'site/index.html'         => $page('footer.css', 'Home'),
        'site/about/index.html'   => $page('footer.css', 'About'),
        'site/contact/index.html' => $page('footer.css', 'Contact'),
        'site/privacy/index.html' => $page('privacy.css', 'Privacy'),
        'site/base.css'           => 'body{margin:0}',
        'site/footer.css'         => $rule,
        'site/privacy.css'        => $rule . 'h1{color:red}',
    ),
))->toArray();

$desktop = '';
foreach ( $compiled['source_reports']['wordpress_site_plan']['template_parts'] as $part ) {
    if ( 'footer-1' === ($part['slug'] ?? null) ) {
        $desktop = (string) $part['canonical_block_markup'];
    }
}
if ( ! str_contains($desktop, '"layout":{"type":"grid"') ) {
    throw new RuntimeException('shared desktop footer lost the grid its member pages load');
}

echo "Shared shell keeps the landmark stylesheet most members load passed\n";
