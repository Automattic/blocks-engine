<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$compile = static function (string $html): string {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<html><head></head><body>' . $html . '</body></html>',
    )))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    return $plan['pages'][0]['canonical_block_markup'];
};

// Captured metadata alone must not make an inert host an opaque editing surface.
$host = '<wow-image class="media" data-image-info="{&quot;displayMode&quot;:&quot;fit&quot;}" data-motion-part="BG_IMG undefined" data-bg-effect-name="" data-has-ssr-src=""><img src="/media/logo.png" alt="Logo"></wow-image>';
$markup = $compile($host);
if (!str_contains($markup, '<!-- wp:group ') || !str_contains($markup, '<!-- wp:image ')
    || str_contains($markup, '/layout-shell') || str_contains($markup, '<!-- wp:html')) {
    throw new RuntimeException('The inert media box and image must both be core-editable: ' . $markup);
}
$document = new DOMDocument();
@$document->loadHTML(preg_replace('/<!--.*?-->/s', '', $markup));
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," media ")]//img[@src][@alt="Logo"]')->length) {
    throw new RuntimeException('The host box must retain its identity around the editable image: ' . $markup);
}

foreach (array(
    'linked thumbnail' => '<a href="/profile">' . $host . '</a>',
    'host geometry' => str_replace('class="media"', 'class="media" style="display:block;width:138px;height:26px"', $host),
    'image geometry' => str_replace('alt="Logo"', 'alt="Logo" width="138" height="26"', $host),
    'out-of-flow ancestor' => '<div style="position:absolute">' . $host . '</div>',
    'stylesheet geometry' => '<style>.media{width:138px;height:26px}</style>' . $host,
) as $case => $html) {
    $markup = $compile($html);
    if (str_contains($markup, '/layout-shell') || str_contains($markup, '<!-- wp:html')
        || !str_contains($markup, '<!-- wp:group ') || !str_contains($markup, '<!-- wp:image ')) {
        throw new RuntimeException($case . ' must retain a core-editable host and image: ' . $markup);
    }
    $document = new DOMDocument();
    @$document->loadHTML(preg_replace('/<!--.*?-->/s', '', $markup));
    $xpath = new DOMXPath($document);
    if (1 !== $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," media ")]//img[@src][@alt="Logo"]')->length) {
        throw new RuntimeException($case . ' lost the media host identity: ' . $markup);
    }
    if ('linked thumbnail' === $case && !str_contains($markup, 'href="/profile"')) {
        throw new RuntimeException('The thumbnail destination must survive: ' . $markup);
    }
}

// Plan shared fragments and route-owned styles together, not just a reduced page.
$files = array('website/parts/footer.html' => '<footer>' . $host . '</footer>');
foreach (array('index', 'about', 'accessibility-statement') as $route) {
    $files['website/' . $route . '.html'] = '<html><head><style>.media{display:block;width:138px;height:26px}</style></head><body>'
        . '<main><h1>' . $route . '</h1>' . ('about' === $route ? $host : '') . '</main>'
        . '<!--#include virtual="/parts/footer.html" --></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
WordPressSitePlan::assertValid($plan);
if (3 !== count($plan['pages'])) {
    throw new RuntimeException('The regression must compile all three routes together.');
}
foreach (array_merge($plan['pages'], $plan['template_parts']) as $document) {
    $markup = $document['canonical_block_markup'];
    if (str_contains($markup, '/layout-shell') || str_contains($markup, '<!-- wp:html')) {
        throw new RuntimeException('Shared and route-local captured image hosts must remain core-editable: ' . $markup);
    }
}

// Native captured boxes must not disable the uncaptured visual-layer carrier's
// intrinsic-height reservation (public compiler/site-plan path).
$markup = $compile('<style>.media-column{position:relative}.visual-layer{position:absolute}</style><div class="media-column"><div class="visual-layer"><media-image><img src="hero.jpg" style="width:320px;height:281px" width="320" height="281" alt="Hero"></media-image></div></div>');
if (!str_contains($markup, '/layout-shell') || !str_contains($markup, '<!-- wp:image ')) {
    throw new RuntimeException('Uncaptured positioned media must retain its geometry carrier and editable image: ' . $markup);
}

fwrite(STDOUT, "custom-image-host-core-group contract passed\n");
