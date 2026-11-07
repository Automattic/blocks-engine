<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A captured custom media host owns a box, not an opaque editing surface.
$html = '<wow-image class="media" data-image-info="{&quot;displayMode&quot;:&quot;fit&quot;}" data-motion-part="BG_IMG undefined" data-bg-effect-name="" data-has-ssr-src=""><img src="/media/logo.png" alt="Logo"></wow-image>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head></head><body>' . $html . '</body></html>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$markup = $plan['pages'][0]['canonical_block_markup'];
if (!str_contains($markup, '<!-- wp:group ') || !str_contains($markup, '<!-- wp:image ')
    || str_contains($markup, '/layout-shell') || str_contains($markup, '<!-- wp:html')) {
    throw new RuntimeException('The retained media box and its image must both be core-editable: ' . $markup);
}
$document = new DOMDocument();
@$document->loadHTML(preg_replace('/<!--.*?-->/s', '', $markup));
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," media ")]//img[@src][@alt="Logo"]')->length) {
    throw new RuntimeException('The host box must retain its identity around the editable image: ' . $markup);
}
fwrite(STDOUT, "custom-image-host-core-group contract passed\n");
