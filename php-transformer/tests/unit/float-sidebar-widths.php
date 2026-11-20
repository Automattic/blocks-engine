<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/float-sidebar-widths.html');
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
$css = '';
array_walk_recursive($result, static function ($value, $key) use (&$css): void {
    if ('content' === $key && is_string($value)) $css .= $value . "\n";
});
if (!str_contains($markup, 'blocks-engine-float-column-widths') || !str_contains($css, 'flex-basis:auto;flex-grow:0;flex-shrink:0')) {
    throw new RuntimeException('Float columns must use the responsive author width, not Core equal-grow sizing.');
}
// Core deliberately loads last; include its important mobile basis and
// desktop nowrap default, not just its equal-grow column rule.
$core = '.wp-block-columns{display:flex;flex-wrap:wrap!important;gap:2em}.wp-block-column{flex-grow:1;min-width:0;overflow-wrap:break-word}'
    . '@media(max-width:781px){.wp-block-columns:not(.is-not-stacked-on-mobile)>.wp-block-column{flex-basis:100%!important}}'
    . '@media(min-width:782px){.wp-block-columns{flex-wrap:nowrap!important}.wp-block-column{flex-basis:0;flex-grow:1}}';
$chrome = getenv('CHROME_BIN') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser'));
if ('' !== $chrome) {
    foreach (array(1440 => array(295, 954, false), 900 => array(200, 700, false), 800 => array(800, 800, true), 600 => array(600, 600, true)) as $width => $expected) {
        $page = '<!doctype html><style>body{margin:0}' . $css . $core . '</style>' . $markup . <<<'JS'
<script>
window.onload = () => {
    const a = document.querySelector('.page-sidebar').getBoundingClientRect();
    const b = document.querySelector('.page-content').getBoundingClientRect();
    const pre = document.createElement('pre');
    pre.id = 'measurement';
    pre.textContent = JSON.stringify([a.width,b.width,b.top>a.top]);
    document.body.append(pre);
};
</script>
JS;
        $file = tempnam(sys_get_temp_dir(), 'float-widths-');
        rename($file, $file . '.html');
        $file .= '.html';
        try {
            file_put_contents($file, $page);
            $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --window-size=' . $width . ',1000 --virtual-time-budget=2000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            if (!preg_match('/<pre id="measurement">(.*?)<\/pre>/s', (string) $output, $match)) throw new RuntimeException('Missing browser measurement.');
            $actual = json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
            if (abs($actual[0] - $expected[0]) > 0.5 || abs($actual[1] - $expected[1]) > 0.5 || $actual[2] !== $expected[2]) {
                throw new RuntimeException('At ' . $width . ': ' . $match[1]);
            }
        } finally {
            unlink($file);
        }
    }
}
echo "Float sidebar responsive widths passed\n";
