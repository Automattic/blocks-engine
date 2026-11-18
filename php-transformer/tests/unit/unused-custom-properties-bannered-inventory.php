<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A framework stylesheet opens with its license banner ahead of the `:root`
// inventory, and the capture ships a report listing every variable the source
// declared. Neither the banner nor the report is a consumer: unreferenced
// inventory tokens must not ship, while the banner and used tokens stay.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'reports' => array('css-variables.json'),
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="paint">Hello</p></main></body></html>',
        'site.css' => "/*!\n * Framework v1 (https://example.test/)\n * Licensed under MIT\n */\n:root{--fw-unused-blue:#0d6efd;--fw-unused-gap:12345px;--fw-paint:teal}.paint{color:var(--fw-paint)}",
        'css-variables.json' => (string) json_encode(array('version' => 1, 'variables' => array(
            array('name' => '--fw-unused-blue', 'value' => '#0d6efd'),
            array('name' => '--fw-unused-gap', 'value' => '12345px'),
            array('name' => '--fw-paint', 'value' => 'teal'),
        ))),
    ),
))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
foreach (array('--fw-unused-blue', '--fw-unused-gap') as $name) {
    if (str_contains($css, $name)) throw new RuntimeException('A bannered, report-listed but unreferenced inventory token must not ship: ' . $name);
}
if (!str_contains($css, '--fw-paint:teal')) throw new RuntimeException('A referenced inventory token must survive');
if (!str_contains($css, 'Licensed under MIT')) throw new RuntimeException('The license banner must survive');
echo "Bannered custom-property inventories are pruned despite capture-report listings\n";
