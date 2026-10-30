<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/positioned-badge.html');
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

$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $markup . '</body></html>');
$xpath = new DOMXPath($document);
$badges = $xpath->query('//mark[contains(@style,"--blocks-engine-richtext-marker:")]');
$assert(false !== $badges && 2 === $badges->length, 'Both badges remain editable RichText marks.');
foreach ( $badges ?: array() as $badge ) {
    $paragraph = $badge->parentNode;
    $assert($paragraph instanceof DOMElement && 'p' === $paragraph->tagName
        && 'blocks-engine-synthetic-paragraph' === $paragraph->getAttribute('class')
        && ! $paragraph->hasAttribute('style'), 'The paragraph is boxless, without a second source selector projection.');
    preg_match('/--blocks-engine-richtext-marker:([^;]+)/', $badge->getAttribute('style'), $marker);
    $assert(isset($marker[1]) && str_contains($css, $marker[1])
        && str_contains($css, 'position:absolute;top:14px;left:14px;z-index:1;background:#e2622a')
        && str_contains($css, 'padding:5px 11px;border-radius:40px'), 'The mark retains the authored position and painted box.');
}

// As in the other geometry regressions, measure when Chrome is available.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ( '' !== $chrome ) {
    $temporary = tempnam(sys_get_temp_dir(), 'badge-');
    $file = $temporary . '.html';
    $boxes = array();
    try {
        foreach ( array($source, '<style>' . $css . '</style>' . $markup) as $page ) {
            $probe = '<script>addEventListener("load",()=>{document.body.dataset.boxes=JSON.stringify([...document.querySelectorAll(".tag,mark")].map(e=>{const r=e.getBoundingClientRect(),c=e.closest("a").getBoundingClientRect();return [r.x-c.x,r.y-c.y,r.width,r.height]}))});</script>';
            file_put_contents($file, $page . $probe);
            $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --virtual-time-budget=1000 --window-size=1200,900 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            preg_match('/data-boxes="([^"]+)"/', (string) $output, $match);
            $boxes[] = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
        }
        $assert(is_array($boxes[0]) && is_array($boxes[1]), 'Chrome measures source and compiled badges.');
        foreach ( $boxes[0] ?? array() as $index => $box ) {
            foreach ( $box as $axis => $value ) {
                $assert(isset($boxes[1][$index][$axis]) && abs($value - $boxes[1][$index][$axis]) <= 1, 'One badge box at the source rect: ' . json_encode($boxes));
            }
        }
    } finally {
        unlink($file);
        unlink($temporary);
    }
}

if ( $failures > 0 ) exit(1);
fwrite(STDOUT, "Positioned badge projection passed.\n");
