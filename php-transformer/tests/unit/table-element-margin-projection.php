<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<style>h2{margin:64px 0 16px} table{margin:24px 0;border-collapse:collapse} td{padding:4px 16px}</style>'
    . '<main><h2>Opening hours</h2><table><tbody><tr><td>Monday</td><td>10–18</td></tr></tbody></table><p>After.</p></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if (str_ends_with((string) ($asset['path'] ?? ''), '.css')) {
        $css .= (string) ($asset['content'] ?? '') . "\n";
    }
}
if (1 !== preg_match('/:where\(\.(blocks-engine-table-[a-z0-9-]+)\)[^{}]*\{margin-top:24px;margin-bottom:24px\}/', $css, $match)) {
    fwrite(STDERR, "FAIL: source table vertical margins must belong to the figure\n{$css}\n");
    exit(1);
}
if (! str_contains($css, '.' . $match[1] . '>table{margin-block:0!important}')) {
    fwrite(STDERR, "FAIL: the inner table must not retain vertical margins\n{$css}\n");
    exit(1);
}
if (! str_contains($css, 'border-collapse:collapse') || ! str_contains($css, 'margin:24px 0')) {
    fwrite(STDERR, "FAIL: other table declarations and horizontal margins must remain\n{$css}\n");
    exit(1);
}
fwrite(STDOUT, "table element margin projection tests: passed\n");
