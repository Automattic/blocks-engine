<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Include expansion is transport: identical inline and included landmarks
// must enter one canonical compilation group, not route-local subgroups.
$header = '<header id="brand"><p>Brand</p><nav><a href="/index.html">Home</a></nav></header>';
$footer = '<footer id="colophon"><p>Copyright</p></footer>';
$files = array('parts/header.html' => $header, 'parts/footer.html' => $footer);
foreach (array('index.html', 'about.html', 'privacy.html', 'terms.html') as $index => $path) {
    $files[$path] = '<html><body><div class="site">'
        . ($index < 2 ? $header : '<!--#include virtual="/parts/header.html" -->')
        . '<main><h1>' . $path . '</h1></main>'
        . ($index < 2 ? $footer : '<!--#include virtual="/parts/footer.html" -->')
        . '</div></body></html>';
}
$shared = (new ArtifactCompiler())->prepareShared(array('entrypoint' => 'index.html', 'files' => $files));
$artifacts = $shared['shared_reduction']['inline_shell_compilation']['artifacts'];
foreach (array('header', 'footer') as $area) {
    $parts = array_values(array_filter($artifacts, static fn(array $part): bool => $area === $part['area']));
    if (1 !== count($parts) || 4 !== count($parts[0]['placement']['source_paths'])) {
        throw new RuntimeException("Identical inline/included {$area} must compile once for all four routes.");
    }
}
echo "Mixed inline/include shell contract passed.\n";
