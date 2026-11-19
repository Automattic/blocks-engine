<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A type-qualified SVG subject (`svg.icon`) follows its class to the
// core/image figure, as `img.icon` does, so the figure owns the SVG's
// out-of-flow box instead of becoming an in-flow flex item (#7010).
$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/positioned-classed-svg-adornment.html');
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $source),
))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$assert(1 === preg_match('/<figure class="wp-block-image icon\b[^"]*"><img\b/', $markup), 'The SVG becomes a core/image figure carrying its class.');
$assert(1 === preg_match('/\.wrap :where\(figure\)[^{,]*\.icon\.wp-block-image\s*\{\s*position:\s*absolute;\s*left:\s*14px/', $css), 'The positioned SVG rule targets the figure: ' . $css);
$assert(! str_contains($css, 'svg.icon {position') && ! str_contains($css, 'svg.icon{position'), 'No positioning is left on the vanished SVG subject.');

// As in the other geometry regressions, measure when Chrome is available.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ( '' !== $chrome ) {
    $temporary = tempnam(sys_get_temp_dir(), 'svg-adornment-');
    $file = $temporary . '.html';
    $boxes = array();
    try {
        foreach ( array($source, '<style>' . $css . '</style>' . $markup) as $page ) {
            $probe = '<script>addEventListener("load",()=>{document.body.dataset.boxes=JSON.stringify([...document.querySelectorAll(".icon,input")].map(e=>{const r=e.getBoundingClientRect(),c=e.closest(".wrap").getBoundingClientRect();return [r.x-c.x,r.y-c.y,r.width,r.height]}))});</script>';
            file_put_contents($file, $page . $probe);
            $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --virtual-time-budget=1000 --window-size=1200,900 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            preg_match('/data-boxes="([^"]+)"/', (string) $output, $match);
            $boxes[] = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
        }
        $assert(is_array($boxes[0]) && is_array($boxes[1]) && 2 === count($boxes[0]) && 2 === count($boxes[1] ?? array()), 'Chrome measures the source and compiled icon and input.');
        foreach ( $boxes[0] ?? array() as $index => $box ) {
            foreach ( $box as $axis => $value ) {
                $assert(isset($boxes[1][$index][$axis]) && abs($value - $boxes[1][$index][$axis]) <= 1, 'Icon and input keep their source rects: ' . json_encode($boxes));
            }
        }
    } finally {
        unlink($file);
        unlink($temporary);
    }
}

if ( $failures > 0 ) exit(1);
fwrite(STDOUT, "Positioned classed SVG adornment passed.\n");
