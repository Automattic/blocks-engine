<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

// A shared utility also matches a non-image subject. Its margin must remain
// there, but move off the synthetic figure onto the image exactly once.
$source = '<style>.space{margin-bottom:16px!important}.photo{display:flex;width:100px;height:60px}'
    . '@media(min-width:992px){.space{margin-bottom:0!important}}</style>'
    . '<h2 class="space">Title</h2><img class="photo space" src="x.jpg">';
$result = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
$dir = sys_get_temp_dir() . '/image-leaf-margin-' . bin2hex(random_bytes(6));
mkdir($dir);
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium'));
if ('' === $chrome) throw new RuntimeException('Browser required for image leaf margin contract.');
try {
    file_put_contents($dir . '/test.html', '<!doctype html><style>' . $css . '</style>'
        . '<style>:where(figure){margin:0 0 1em}</style>' . $result['serialized_blocks']
        . '<script>document.body.dataset.margins=["h2","figure","img"].map(s=>getComputedStyle(document.querySelector(s)).marginBottom).join(",")</script>');
    foreach (array(390 => '16px,0px,16px', 1200 => '0px,0px,0px') as $width => $expected) {
        $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome)
            . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($dir . '/profile')
            . ' --window-size=' . $width . ',800 --dump-dom --virtual-time-budget=1000 '
            . escapeshellarg('file://' . $dir . '/test.html') . ' 2>/dev/null');
        if (!str_contains((string) $output, 'data-margins="' . $expected . '"')) {
            throw new RuntimeException('Image margin must occur once at width ' . $width . ': ' . $output);
        }
    }
} finally {
    shell_exec('rm -rf ' . escapeshellarg($dir));
}
echo "image-leaf-margin-once: ok\n";
