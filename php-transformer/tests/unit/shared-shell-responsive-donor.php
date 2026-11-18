<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SourceShells;

// A responsive sample on a non-entry route must not elect that route as
// the shared presentation donor. Real entrance evidence still elects a donor.
$sample = '<header><a class="data-blocks-engine-capture-responsive-class-desktop-only" href="/">Home</a></header>';
if (0 !== SourceShells::evidenceCount($sample)) throw new RuntimeException('Responsive samples must not rank shared-shell donors.');
$entrance = '<header data-blocks-engine-capture-viewport-entrance="{}">Header</header>';
if (1 !== SourceShells::evidenceCount($entrance)) throw new RuntimeException('Entrance evidence must still rank donors.');
$page = static fn(string $header): string => '<html><head><title>Page</title></head><body><div class="site">' . $header . '<main><h1>Page</h1></main><footer>Footer</footer></div></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('<header><a href="/">Home</a></header>'),
    'about.html' => $page($sample),
)))->toArray();
$headers = 0;
foreach ($result['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array() as $artifact) {
    if ('header' !== ($artifact['area'] ?? null)) continue;
    ++$headers;
    if (null !== ($artifact['placement']['evidence_source_path'] ?? null)) throw new RuntimeException('Responsive samples cannot force a shared presentation donor.');
}
if (1 !== $headers) throw new RuntimeException('Expected a source-shared header artifact.');
echo "Shared shell responsive donor: passed\n";
