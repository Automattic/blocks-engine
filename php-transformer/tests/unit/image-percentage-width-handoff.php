<?php
declare(strict_types=1);

/** A percentage width is resolved once, on the synthetic figure (#5864). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium'));
if ('' === $chrome) throw new RuntimeException('A browser is required');
$source = '<style>.frame{width:800px}@media(min-width:600px){.photo{width:75%;float:right}}img{max-width:100%;height:auto}</style>'
    . '<div class="frame"><img class="photo" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'1200\' height=\'800\'%3E%3C/svg%3E"></div>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
$temp = tempnam(sys_get_temp_dir(), 'image-width-');
$file = $temp . '.html';
unlink($temp);
$profile = sys_get_temp_dir() . '/image-width-profile-' . getmypid();
try {
    file_put_contents($file, '<!doctype html><style>' . $css . '</style>' . $result['serialized_blocks']
        . '<script>window.onload=()=>{const r=document.querySelector("img").getBoundingClientRect();document.body.dataset.box=r.width+"x"+r.height}</script>');
    $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome)
        . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($profile)
        . ' --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
    if (!str_contains((string) $output, 'data-box="600x400"')) {
        throw new RuntimeException('Percentage width must not compound on the leaf: ' . $output);
    }
} finally {
    unlink($file);
    shell_exec('rm -rf ' . escapeshellarg($profile));
}
echo "Image percentage width handoff passed\n";
