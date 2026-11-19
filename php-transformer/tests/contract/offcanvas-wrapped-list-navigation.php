<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6807: a hamburger's offcanvas panel holds its menu list inside one
// wrapper div. The panel itself must become the mobile core/navigation (as
// it does without the wrapper), carrying its anchor and the wrapper's
// classes, so Core's opener does not render inside the hidden panel group.
$html = '<!doctype html><html><head><style>.navbar-toggler{display:none}'
    . '.offcanvas{position:fixed;top:0;right:0;bottom:0;visibility:hidden;transform:translateX(100%)}'
    . '@media(max-width:991px){.navbar-toggler{display:block;width:35px;height:28px}}</style></head><body>'
    . '<header><nav class="navbar" aria-label="Main menu"><div class="container"><a class="navbar-brand" href="https://example.com/">Brand</a>'
    . '<button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#mob-nav" aria-controls="mob-nav"><span></span></button>'
    . '<div id="mob-nav" class="offcanvas offcanvas-end" tabindex="-1"><div id="mobileMenu" class="h-100 d-flex flex-column pb-5">'
    . '<ul class="nav navbar-nav"><li class="nav-item"><a class="nav-link" href="https://example.com/about/">About</a></li>'
    . '<li class="nav-item"><a class="nav-link" href="https://example.com/events/">Events</a></li></ul>'
    . '</div></div></div></nav></header><main><h1>Contact</h1></main></body></html>';

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$markup = implode("\n", array_column($plan['template_parts'], 'canonical_block_markup'));

$failures = array();
if ( 1 !== preg_match('/<!-- wp:navigation (\{[^>]*\}) \/?-->/', $markup, $match) ) {
    $failures[] = 'no core/navigation emitted';
} else {
    $attrs = json_decode($match[1], true);
    $classes = preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array();
    if ( 'mobile' !== ($attrs['overlayMenu'] ?? null) ) {
        $failures[] = 'navigation is not a mobile overlay';
    }
    if ( 'mob-nav' !== ($attrs['anchor'] ?? null) ) {
        $failures[] = 'navigation does not carry the panel anchor';
    }
    foreach ( array( 'offcanvas', 'h-100', 'd-flex', 'pb-5', 'navbar-nav' ) as $class ) {
        if ( ! in_array($class, $classes, true) ) {
            $failures[] = "navigation lost source class {$class}";
        }
    }
}
if ( 1 === preg_match('/<!-- wp:group \{[^>]*"className":"[^"]*\boffcanvas\b/', $markup) ) {
    $failures[] = 'offcanvas panel survives as a core/group around the navigation';
}

if ( array() !== $failures ) {
    fwrite(STDERR, "FAIL offcanvas-wrapped-list-navigation:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS offcanvas-wrapped-list-navigation\n";
