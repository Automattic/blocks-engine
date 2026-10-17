<?php
declare(strict_types=1);

// Core's `.wp-block-table td, th { border; padding }` is unlayered, so it beats
// author cell rules inside an `@layer` (Tailwind utilities) whatever their
// specificity. When every author rule setting a family on the cells is layered,
// the table normalization reverts the unlayered layer for it so the author's
// layered cascade decides (#2927). Unlayered author rules keep today's output.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (string $css): string {
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><table><tbody>'
        . '<tr><td class="cell lead">Who builds it?</td><td class="cell">You do</td></tr>'
        . '<tr><td class="cell lead">Cost</td><td class="cell">Low</td></tr>'
        . '</tbody></table></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $out = '';
    foreach ($result['assets'] ?? array() as $asset) {
        if (str_ends_with((string) ($asset['path'] ?? ''), '.css')) {
            $out .= (string) ($asset['content'] ?? '') . "\n";
        }
    }
    return $out;
};
$fail = static function (string $message, string $css): void {
    fwrite(STDERR, "FAIL: {$message}\n{$css}\n");
    exit(1);
};
$revert = static fn(string $css): ?string => 1 === preg_match('/\.(blocks-engine-table-[a-z0-9-]+)>table th,\.\1>table td\{([^{}]*revert-layer[^{}]*)\}/', $css, $match) ? $match[2] : null;

$layered = $compile('@layer base,utilities;@layer base{*{padding:0;border:0 solid}}@layer utilities{.cell{padding:16px 20px;border-bottom:1px solid #234}.lead{font-weight:700}@media (max-width:640px){.cell{padding:8px}}}');
$declarations = $revert($layered);
if ('border:revert-layer;padding:revert-layer' !== $declarations) {
    $fail('layered author cell padding and border revert core\'s unlayered table defaults', $layered);
}
if (1 !== preg_match('/@layer utilities\{[^@]*\.blocks-engine-table-[a-z0-9-]+>table>tbody>tr:nth-child\([1n]\)>(?:td|:is\(td,th\)):nth-child\([1n]\)[^{}]*\{[^}]*padding/', $layered)) {
    $fail('the projected cell padding stays in the author layer', $layered);
}

$unlayered = $compile('.cell{padding:16px 20px;border-bottom:1px solid #234}');
if (null !== $revert($unlayered)) {
    $fail('unlayered author cell rules keep the existing normalization', $unlayered);
}

// A family an unlayered author rule also sets is not reverted: revert-layer
// would discard that unlayered author declaration too.
$mixed = $compile('@layer utilities{.cell{padding:16px 20px;border-bottom:1px solid #234}}td.lead{padding:4px}');
if ('border:revert-layer' !== $revert($mixed)) {
    $fail('only the family set exclusively by layered rules is reverted', $mixed);
}

fwrite(STDOUT, "table cell layered author box tests: passed\n");
