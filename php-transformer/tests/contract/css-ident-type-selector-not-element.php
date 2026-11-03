<?php
declare(strict_types=1);

// A type selector whose name no HTML element can have, such as Stitches' `--sxs{--sxs:0}`
// hydration rule, compiles like any other unmatched rule instead of failing the page.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fail = static function (string $label, string $detail): void {
    fwrite(STDERR, "FAIL: {$label}\n{$detail}\n");
    exit(1);
};
$html = '<!DOCTYPE html><html><head><title>T</title><style>--sxs{--sxs:0 t-abc}p{color:#222}</style></head>'
    . '<body><main><h1>Heading</h1><p>Paragraph text.</p></main></body></html>';
try {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
} catch (\Throwable $error) {
    $fail('page with a non-element type selector compiles', get_class($error) . ': ' . $error->getMessage());
}
$encoded = (string) json_encode($result);
if (!str_contains($encoded, 'Paragraph text.')) $fail('page content survives', $encoded);
if (str_contains($encoded, 'blocks-engine-source---')) $fail('no marker is allocated for the non-element name', $encoded);

echo "ok\n";
