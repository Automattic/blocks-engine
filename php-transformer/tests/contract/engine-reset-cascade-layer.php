<?php
declare(strict_types=1);

/** Engine before-author resets must not lose to layered author CSS (#2862). */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$css = static function (string $authorCss): string {
    $html = '<style>' . $authorCss . '</style><ul class="grid"><li class="p-8"><span>icon</span><h3 class="mt-6">Title</h3><p class="mt-2">Body</p></li></ul>';
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    return implode("\n", array_map(static fn (array $a): string => 'css' === ($a['kind'] ?? '') ? (string) ($a['content'] ?? '') : '', $result['assets'] ?? array()));
};
$base = '.grid{display:grid}.p-8{padding:2rem}.mt-6{margin-top:1.5rem}.mt-2{margin-top:.5rem}';

$layered = $css('@layer base,utilities;@layer utilities{' . $base . '}');
$assert(1 === preg_match('/@layer\s+blocks-engine-reset\s*,\s*base\s*,\s*utilities\s*;/', $layered), 'layer order names the engine layer before base');
$assert(1 === preg_match('/@layer blocks-engine-reset\{[^@]*blocks-engine-css-owned-flow[^@]*\}/', $layered), 'css-owned-flow reset is emitted inside the engine layer');

$plain = $css($base);
$assert(! str_contains($plain, 'blocks-engine-reset') && ! str_contains($plain, '@layer'), 'unlayered author CSS output is unchanged');
$assert(str_contains($plain, 'blocks-engine-css-owned-flow'), 'unlayered output keeps the plain reset');
// A capture tool's own layer (for example the disclosure base) is capture
// vocabulary, not author layering: unlayered author CSS keeps losing to the
// engine resets by specificity, so the resets stay unlayered.
$captureLayer = '@layer data-blocks-engine-capture-disclosure-base{:where(details.data-blocks-engine-capture-disclosure>summary){list-style:none}}';
$captured = $css($captureLayer . $base);
$assert(! str_contains($captured, 'blocks-engine-reset'), 'a capture-vocabulary layer alone leaves the engine resets unlayered');
$assert(str_contains($captured, 'blocks-engine-css-owned-flow'), 'capture-layered output keeps the plain reset');
$assert(1 === preg_match('/@layer\s+data-blocks-engine-capture-disclosure-base\s*;/', $captured), 'the capture layer order is still pinned ahead of engine CSS');

$mixed = $css($captureLayer . '@layer utilities{' . $base . '}');
$assert(1 === preg_match('/@layer\s+blocks-engine-reset\s*,\s*data-blocks-engine-capture-disclosure-base\s*,\s*utilities\s*;/', $mixed), 'an authored layer next to the capture layer still moves the resets into the engine layer');
// Core's unlayered figure margin must not return when the author uses layers
// (#4233). Exercise both the default and a projected layered author margin.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium'));
$assert('' !== $chrome, 'a browser is required for the synthetic figure margin contract');
$temp = tempnam(sys_get_temp_dir(), 'synthetic-margin-');
$file = $temp . '.html';
unlink($temp);
$profile = '/tmp/synthetic-margin-profile-' . getmypid();
try {
    foreach (array(false, true) as $authored) {
        $source = '<style>@layer a{.x{margin-bottom:5px}}</style><div><img '
            . ($authored ? 'class="x" ' : '') . 'src="x.jpg"></div>';
        $result = (new HtmlTransformer())->transform($source)->toArray();
        $outputCss = implode("\n", array_column(array_filter($result['assets'], static fn (array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
        // The WordPress block-library figure default is deliberately unlayered
        // and loaded last, so source order cannot hide a layered reset regression.
        file_put_contents($file, '<!doctype html><style>' . $outputCss . '</style>'
            . '<style>:where(figure){margin:0 0 1em}</style>' . $result['serialized_blocks']
            . '<script>document.body.dataset.margin=getComputedStyle(document.querySelector("figure")).marginBottom</script>');
        $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --user-data-dir='
            . escapeshellarg($profile) . ' --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        $expected = $authored ? '5px' : '0px';
        $assert(str_contains((string) $output, 'data-margin="' . $expected . '"'), 'synthetic figure bottom margin is ' . $expected . ' against unlayered Core CSS: ' . (string) $output);
    }
} finally {
    @unlink($file);
    shell_exec('rm -rf ' . escapeshellarg($profile));
}
echo "ok\n";
