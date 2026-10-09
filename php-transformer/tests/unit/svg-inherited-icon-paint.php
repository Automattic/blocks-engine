<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A Wix icon button paints its <svg> through the wrapper it sits in
// (`.StylableButton__icon{fill:...}`), under a width query in a dual-device
// capture. The SVG becomes a standalone image that cannot see that wrapper,
// so the inherited fill must be baked onto the image root. Otherwise the
// gold phone icon renders black.
$svg = '<svg viewBox="0 0 200 200" width="200" height="200" data-type="shape"><g><path d="M20 20h160v160H20z"></path></g></svg>';
$source = '<style>@media (min-width:768px){.btn .icon{fill:rgb(205,168,40)}}'
    . '.btn{display:block;width:62px;height:62px;border-radius:200px;background:#fff}'
    . '.btn .icon{display:block;width:30px;height:30px}.btn .icon svg{width:100%;height:100%}.btn .label{display:none}</style>'
    . '<header><div id="c1"><a class="btn wixui-button" href="tel:123" aria-label="Phone"><span class="box"><span class="label">Phone</span>'
    . '<span class="icon" aria-hidden="true"><span>' . $svg . '</span></span></span></a></div></header><main><p>Body</p></main>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$output = stripslashes(json_encode($compiled, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

if (!preg_match_all('/<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg"[^>]*>/', $output, $assets)) {
    throw new RuntimeException('Fixture must materialize the icon as a standalone SVG image.');
}
foreach (array_unique($assets[0]) as $root) {
    if (!str_contains($root, 'fill:rgb(205,168,40)')) {
        throw new RuntimeException("The standalone icon must keep the fill its wrapper gave it.\n" . $root);
    }
}
echo "SVG inherited icon paint passed\n";
