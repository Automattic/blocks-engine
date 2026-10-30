<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$fixture = require dirname(__DIR__) . '/fixtures/source-responsive-cascade.php';
$result = (new HtmlTransformer())->transform($fixture['html'], array('static_css' => $fixture['css']))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
$assert(!preg_match('/(?:^|[;{])(?:min-|max-)?(?:height|width):[^;}]*!important/i', $css), 'Responsive nodes have no invented important geometry.');
$assert(str_contains($css, '@supports(display:grid){@container(min-width:600px){'), 'Nested supports/container conditions survive projection.');
$assert(str_contains($css, '@layer utilities{'), 'Source layers survive projection.');
$walk = static function (array $blocks) use (&$walk, $assert): void {
    foreach ($blocks as $block) {
        if (in_array($block['blockName'], array('core/image', 'core/embed'), true) || str_ends_with($block['blockName'], '/visual-iframe')) {
            foreach (array('width', 'height') as $axis) {
                $value = $block['attrs'][$axis] ?? '';
                $assert(!is_numeric($value) && !preg_match('/^\d+(?:\.\d+)?px$/', (string) $value), 'No captured pixel geometry in responsive block attributes.');
            }
        }
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($result['blocks']);
// Scoping must not allocate semantic wrapper identities: bare author image
// rules still address the leaf, and every factory figure supplies the scope.
$scopeResult = (new HtmlTransformer())->transform(
    '<div style="display:flex"><img class="photo" src="https://example.org/photo.png"></div><figure><img src="https://example.org/photo.png"></figure><p><a href="/photo"><img src="https://example.org/photo.png"></a></p>',
    array('static_css' => '*{box-sizing:border-box}img{display:block;max-width:100%}.photo{max-width:95%}')
)->toArray();
$scopeCss = implode("\n", array_column(array_filter($scopeResult['assets'], static fn (array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
$scopeDom = new DOMDocument();
@$scopeDom->loadHTML($scopeResult['serialized_blocks']);
$scopeFigures = $scopeDom->getElementsByTagName('figure');
$assert(3 === $scopeFigures->length, 'Real, synthetic flex and linked images emit native figures.');
foreach ($scopeFigures as $figure) {
    $assert(in_array('blocks-engine-image', explode(' ', $figure->getAttribute('class')), true), 'Every emitted image figure carries the factory scope.');
}
$assert(str_contains($scopeCss, ':where(.blocks-engine-image).wp-block-image > img'), 'Universal image arms use a zero-specificity importer scope.');
$assert(!str_contains($scopeCss, '*.wp-block-image'), 'No superseded unscoped universal image arms survive.');
$assert(array() === (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CoreSelectorScoping())->violations($scopeCss), 'All projected image arms satisfy core-selector scoping.');
// Navigation is dynamic in core. Materialize its public block attributes into
// core's frontend shape; all other nodes use the actual emitted save markup.
$markup = preg_replace_callback('/<!-- wp:navigation (\{.*?\}) -->(.*?)<!-- \/wp:navigation -->/s', static function (array $m): string {
    $attrs = json_decode($m[1], true);
    $items = '';
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $m[2], $links);
    foreach ($links[1] as $json) {
        $link = json_decode($json, true);
        $items .= '<li class="wp-block-navigation-item"><a href="' . $link['url'] . '">' . $link['label'] . '</a></li>';
    }
    return '<nav class="wp-block-navigation ' . $attrs['className'] . '"><ul style="display:contents;list-style:none">' . $items . '</ul></nav>';
}, $result['serialized_blocks']);
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium'));
$assert('' !== $chrome, 'A browser is required for responsive cascade fingerprints.');
$temp = tempnam('/tmp', 'responsive-cascade-');
$file = $temp . '.html';
unlink($temp);
$profile = '/tmp/responsive-cascade-profile-' . getmypid();
try {
    foreach (array(390, 599, 600, 601, 639, 640, 641, 767, 768, 769, 798, 799, 800, 899, 900, 901, 1023, 1024, 1025, 1440) as $width) {
        $html = '<!doctype html><body>';
        // Give container queries a real query container in both documents.
        foreach (array('<style>' . $fixture['css'] . '</style>' . $fixture['html'], '<style>' . $css . '</style>' . $markup) as $doc) {
            $doc = '<!doctype html><style>html{container-type:inline-size}iframe{pointer-events:none}</style>' . $doc;
            // No external network dependencies: intrinsic image ratio is local;
            // iframe content cannot influence the replaced element's geometry.
            $doc = str_replace('https://example.org/photo.png', 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"/>'), $doc);
            $doc = preg_replace('/src="https:\/\/www.youtube.com[^"]*"/', 'src="about:blank"', $doc);
            $html .= '<iframe style="border:0;width:' . $width . 'px;height:3000px" srcdoc="' . htmlspecialchars($doc, ENT_QUOTES) . '"></iframe>';
        }
        $html .= <<<'JS'
<script>onload=()=>{document.body.dataset.result=JSON.stringify([...document.querySelectorAll('body>iframe')].map(f=>{const d=f.contentDocument,w=f.contentWindow;return ['nav','a[href="/start"]','.frame','.padding-frame','.media-grid','.media-grid img'].map(q=>{const e=d.querySelector(q),s=w.getComputedStyle(e);return [s.display,s.width,s.height,s.paddingTop,s.paddingRight,s.paddingBottom,s.paddingLeft,s.columnGap,s.opacity,s.letterSpacing,s.gridTemplateColumns,s.color,s.backgroundColor,s.objectFit,s.position]})}))}</script>
JS;
        file_put_contents($file, $html);
        $output = shell_exec('TMPDIR=/tmp ' . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($profile) . ' --dump-dom --virtual-time-budget=2000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        preg_match('/data-result="([^"]+)"/', (string) $output, $data);
        $styles = json_decode(html_entity_decode($data[1] ?? '', ENT_QUOTES), true);
        $assert(2 === count($styles ?? array()), 'Browser measured source and public output at ' . $width);
        $assert($styles[0] === $styles[1], 'Source/output fingerprints at ' . $width . ': ' . json_encode($styles));
        $assert(($width < 768 ? 'none' : 'flex') === $styles[0][0][0], 'Nav changes at md, not at capture width.');
        $assert(($width < 600 ? 'normal' : '1px') === $styles[0][1][9], 'Container query is active on either side of its source breakpoint.');
        $assert('12px' === $styles[0][0][7], 'Later source rule beats container gap without changing importance.');
        $assert('0.9' === $styles[0][1][8], 'Source important paint remains important.');
        $assert(($width < 800 ? 1 : 2) === count(explode(' ', $styles[0][4][10])), 'Fractional tracks stack only at the source breakpoint.');
    }
} finally {
    @unlink($file);
    shell_exec('rm -rf ' . escapeshellarg($profile));
}
echo "source-responsive-cascade contract passed\n";
