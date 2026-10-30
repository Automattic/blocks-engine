<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/conditional-flex-button-bar.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
preg_match_all('/class="wp-block-buttons (blocks-engine-control-[^ "]+)/', $result['serialized_blocks'], $matches);
if (2 !== count($matches[1])) {
    fwrite(STDERR, "FAIL: both source links must become controls\n");
    exit(1);
}
foreach ($matches[1] as $marker) {
    $wrapper = ':where(.' . $marker . '.wp-block-buttons)';
    $button = $wrapper . '>:where(.' . $marker . '.wp-block-button)';
    foreach (array(
        $wrapper . '{display:block!important;gap:0!important',
        $wrapper . '{width:auto!important}',
        $button . '{width:100%!important;height:100%}',
        $button . '>:where(.wp-block-button__link){width:100%!important;height:100%}',
    ) as $rule) {
        if (!str_contains($css, $rule)) {
            fwrite(STDERR, "FAIL: conditional flex control fills its source flex share: {$rule}\n");
            exit(1);
        }
    }
}
// Exercise the actual boxes at phone width with the relevant core save CSS.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $core = '.wp-block-buttons{display:flex;flex-wrap:wrap;align-items:center;gap:.5em}'
        . '.wp-block-button{display:inline-block}.wp-block-button__link{display:inline-block;box-sizing:border-box;padding:calc(.667em + 2px) calc(1.333em + 2px)}';
    $probe = '<script>addEventListener("load",()=>{parent.document.body.dataset.widths=JSON.stringify([...document.querySelectorAll("a")].map(a=>a.getBoundingClientRect().width))})</script>';
    $temporary = tempnam(sys_get_temp_dir(), 'conditional-flex-');
    $file = $temporary . '.html';
    try {
        $widths = array();
        foreach (array($source, '<style>' . $core . '</style><style>' . $css . '</style>' . $result['serialized_blocks']) as $page) {
            $page = preg_replace('~src="assets/materialized-svg/[^"]+"~', 'src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2218%22 height=%2218%22/%3E"', $page) ?? $page;
            $frame = '<!doctype html><html><body>' . $page . $probe . '</body></html>';
            file_put_contents($file, '<!doctype html><body><iframe style="border:0;width:390px;height:900px" srcdoc="' . htmlspecialchars($frame, ENT_QUOTES) . '"></iframe></body>');
            $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            preg_match('/data-widths="([^"]+)"/', (string) $output, $match);
            $widths[] = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
        }
        foreach (array(0, 1) as $link) {
            if (!isset($widths[0][$link], $widths[1][$link]) || abs($widths[0][$link] - 194.5) > 1 || abs($widths[0][$link] - $widths[1][$link]) > 1) {
                fwrite(STDERR, 'FAIL: both links fill equal source flex shares at 390px: ' . json_encode($widths) . "\n");
                exit(1);
            }
        }
    } finally {
        unlink($file);
        unlink($temporary);
    }
}
echo "Conditional flex button bar tests: passed\n";
