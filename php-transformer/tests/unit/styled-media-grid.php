<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/styled-media-grid.html');
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array( 'index.html' => $source ),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
$css = implode("\n", array_column($plan['assets'], 'content'));
$assert(! str_contains($markup, 'wp:media-text'), 'A decorated media wrapper keeps the source grid, without core content padding.');
$assert(1 === preg_match('/<div class="wp-block-group photo[^\"]*"/', $markup), 'The photo remains an editable wrapper.');
foreach ( array( 'grid-template-columns:1.25fr 1fr', 'gap:48px', 'border-radius:12px', 'box-shadow:0 10px 30px rgba(0,0,0,.2)', 'aspect-ratio:4/3', 'overflow:hidden', 'object-fit:cover' ) as $declaration ) {
    $assert(str_contains($css . $markup, $declaration), 'Retain authored geometry: ' . $declaration);
}

// Compare actual text/photo rectangles and computed decoration, not just CSS
// strings. A neutral intrinsic image avoids depending on a network asset.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ( '' !== $chrome ) {
    $core = '.wp-block-image{margin:0 0 1em}.wp-block-image img{box-sizing:border-box;height:auto;max-width:100%;vertical-align:bottom}';
    $probe = '<script>addEventListener("load",()=>{parent.document.body.dataset.boxes=JSON.stringify([".body",".photo",".photo img"].map(s=>{const e=document.querySelector(s),r=e.getBoundingClientRect(),c=getComputedStyle(e);return {rect:[r.x,r.y,r.width,r.height],radius:c.borderRadius,shadow:c.boxShadow,ratio:c.aspectRatio,overflow:c.overflow}}))});</script>';
    $image = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%221000%22 height=%22500%22/%3E';
    $markup = preg_replace('/src="[^"]+"/', 'src="' . $image . '"', $markup);
    $source = str_replace('photo.jpg', $image, $source);
    $temporary = tempnam(sys_get_temp_dir(), 'styled-grid-');
    $file = $temporary . '.html';
    try {
        $measurements = array();
        foreach ( array($source, '<style>' . $core . $css . '</style>' . $markup) as $page ) {
            $frame = '<!doctype html><html><body>' . $page . $probe . '</body></html>';
            file_put_contents($file, '<!doctype html><body><iframe style="border:0;width:1180px;height:900px" srcdoc="' . htmlspecialchars($frame, ENT_QUOTES) . '"></iframe></body>');
            $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            preg_match('/data-boxes="([^"]+)"/', (string) $output, $match);
            $boxes = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
            $assert(is_array($boxes), 'Browser measured text and media boxes.');
            $measurements[] = $boxes;
        }
        foreach ( $measurements[0] as $index => $box ) {
            foreach ( $box['rect'] as $axis => $value ) {
                $assert(abs($value - $measurements[1][$index]['rect'][$axis]) <= 1, 'Text/photo geometry matches source within 1px: ' . json_encode($measurements));
            }
            foreach ( array( 'radius', 'shadow', 'ratio', 'overflow' ) as $property ) {
                $assert($box[$property] === $measurements[1][$index][$property], 'Computed media decoration matches: ' . $property);
            }
        }
    } finally {
        unlink($file);
        unlink($temporary);
    }
}
echo "Styled media grid ok\n";
