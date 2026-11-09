<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A route stylesheet that @imports a sibling local stylesheet is not a web-font provider.
$files = array(
	'style.css'      => "@import url('https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap');\nbody{font:16px/1.6 'Lato',sans-serif}",
	'index.html'     => '<!doctype html><html lang="en"><head><title>Home</title><link rel="stylesheet" href="style.css"></head><body><main><h1>Bakery</h1><p><a href="sub/index.html">Other</a></p></main></body></html>',
	'sub/style.css'  => "@import url(\"../style.css\");\nh1{color:#722F3E}",
	'sub/index.html' => '<!doctype html><html lang="en"><head><title>Home</title><link rel="stylesheet" href="style.css"></head><body><main><h1>Bakery</h1><p><a href="../index.html">Other</a></p></main></body></html>',
);
$result = json_encode((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
if (str_contains($result, 'webfont_import_unsupported_provider')) {
	throw new RuntimeException('A local stylesheet @import was classified as an unsupported web-font provider.');
}
echo "local stylesheet import font materialization contract passed\n";
