<?php
declare(strict_types=1);

/** Source-owned grid minima, exercised through public compilation and Chromium. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$issue = '<!doctype html><html><head><style>body{margin:0;font:16px Georgia,serif}.wrap{width:358px}.layout{display:grid;grid-template-columns:.82fr 1.18fr;gap:42px;align-items:center}.layout>div:first-child{padding-left:25px}.layout h2{font-size:34px;line-height:1.13;margin:0}.layout p{font-size:13px;margin:0}</style></head>'
    . '<body><section><div class="wrap layout"><div><span>Brand</span><h2>Travel planning shaped around your trip.</h2></div><div><p>Based in Hounslow, London, the team helps customers enquire about travel arrangements and services.</p></div></div></section></body></html>';
$card = static fn (string $overflow): string => '<style>body{margin:0}.samples{display:grid;width:327px;gap:2rem}figure{margin:0}'
    . '@media(min-width:640px){.samples{grid-template-columns:repeat(2,minmax(0,1fr))}}'
    . '.frame{' . $overflow . ';border:1px solid #ccc}.shot{aspect-ratio:8/5;width:100%;object-fit:cover}</style>'
    . '<section class="samples"><figure><div class="frame"><img src="one.png" width="1600" height="1000" class="shot"></div><figcaption>One</figcaption></figure></section>';
$cases = array(
    'issue fractional tracks keep min-content' => array($issue, '.layout', false),
    'removed overflow frame' => array($card('overflow:hidden'), '.samples', true),
    'renamed source classes' => array(str_replace(array('samples', 'frame', 'shot'), array('tiles', 'crop', 'media'), $card('overflow:hidden')), '.tiles', true),
    'visible frame control' => array($card('overflow:visible'), '.samples', false),
    'specificity wins over later visible' => array($card('overflow:visible') . '<style>.samples .frame{overflow:hidden}.frame{overflow:visible}</style>', '.samples', true),
    'inline visible beats class overflow' => array(str_replace('class="frame"', 'class="frame" style="overflow:visible"', $card('overflow:hidden')), '.samples', false),
    'important author overflow beats inline' => array(str_replace('class="frame"', 'class="frame" style="overflow:visible"', $card('overflow:hidden!important')), '.samples', true),
    'conditional source overflow' => array($card('overflow:visible') . '<style>@media(max-width:600px){.frame{overflow:hidden}}</style>', '.samples', true),
    'source custom property overflow' => array($card('--clip:hidden;overflow:var(--clip)'), '.samples', true),
    'explicit source item minimum' => array(str_replace('.layout>div:first-child{', '.layout>div:first-child{min-width:0;', $issue), '.layout', false),
    'source item overflow' => array(str_replace('.layout>div:first-child{', '.layout>div:first-child{overflow:hidden;', $issue), '.layout', true),
);
$browser = trim((string) shell_exec('command -v chromium 2>/dev/null'));
$scratch = sys_get_temp_dir() . '/grid-minimum-' . bin2hex(random_bytes(6));
mkdir($scratch);
$temp = $scratch . '/page.html';
// A real 1600x1000 image, so both pages measure a loaded image rather than a
// broken placeholder whose size depends on when Chromium gives up on it.
$chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
file_put_contents($scratch . '/one.png', "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1600, 1000, 8, 0, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress(str_repeat("\0" . str_repeat("\x80", 1600), 1000))) . $chunk('IEND', ''));
$measure = static function (string $html, string $selector) use ($browser, $temp): array {
    // Measure after `load`, once the fixture image has decoded.
    $script = '<script>addEventListener("load",()=>{const grid=document.querySelector(' . json_encode($selector) . ');'
        . 'document.body.appendChild(Object.assign(document.createElement("pre"),{id:"measure",textContent:JSON.stringify({'
        . 'tracks:getComputedStyle(grid).gridTemplateColumns.split(" ").map(parseFloat),'
        . 'items:[...grid.children].map(n=>({min:getComputedStyle(n).minWidth,overflow:getComputedStyle(n).overflow,width:n.getBoundingClientRect().width}))'
        . '})}));});</script>';
    file_put_contents($temp, $html . $script);
    $dom = shell_exec(escapeshellarg($browser) . ' --headless --no-sandbox --disable-gpu --allow-file-access-from-files --window-size=390,844 --dump-dom ' . escapeshellarg('file://' . $temp) . ' 2>/dev/null');
    if (! preg_match('/<pre id="measure">(.*?)<\/pre>/s', (string) $dom, $match)) {
        throw new RuntimeException('Browser did not return grid measurements');
    }
    return json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
};
try {
    foreach ($cases as $name => [$source, $selector, $clipped]) {
        $result = (new HtmlTransformer())->transform($source, array())->toArray();
        $markup = (string) ($result['serialized_blocks'] ?? '');
        $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
        if (! str_contains($markup, 'blocks-engine-css-owned-grid') || str_contains($css, ':root :where(.blocks-engine-css-owned-grid)>*{min-width:0}')) {
            throw new RuntimeException($name . ': missing grid or blanket reset returned');
        }
        $native = '<!doctype html><style>' . $css . '</style>' . $markup;
        if ('' !== $browser) {
            $before = $measure($source, $selector);
            $after = $measure($native, $selector);
            foreach ($before['tracks'] as $i => $width) {
                if (abs($width - ($after['tracks'][$i] ?? -1000)) > 1) {
                    throw new RuntimeException($name . ': source/native track mismatch ' . json_encode(array($before, $after)));
                }
            }
            foreach ($before['items'] as $i => $item) {
                if ($item['min'] !== $after['items'][$i]['min']) {
                    throw new RuntimeException($name . ': changed source item minimum');
                }
            }
            if ('issue fractional tracks keep min-content' === $name) {
                foreach ($after['items'] as $item) {
                    if ('auto' !== $item['min'] || 'visible' !== $item['overflow']) throw new RuntimeException('Issue items lost automatic minimum');
                }
            }
            if ($clipped && ! in_array($after['items'][0]['overflow'], array('hidden', 'auto', 'scroll'), true)) {
                throw new RuntimeException($name . ': lost source overflow carve-out');
            }
        } else {
            // Public emitted-CSS/markup fallback when no real browser is installed.
            if ($clipped && ! preg_match('/overflow\s*:\s*hidden/', $css)) throw new RuntimeException($name . ': missing overflow');
        }
        echo "PASS: {$name}\n";
    }
} finally {
    array_map('unlink', glob($scratch . '/*') ?: array());
    rmdir($scratch);
}
