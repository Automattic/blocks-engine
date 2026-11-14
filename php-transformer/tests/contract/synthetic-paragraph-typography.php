<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Acceptance input from #5886, unchanged. The second row supplies the layout
// context which promotes the linked inline wrapper to a group.
$fixture = <<<'HTML'
<style>.name{font:600 12px/1.45 sans-serif;color:#35463f}</style>
<div class="detail"><span class="city">Makkah</span><span class="name"><a href="/hotels/a/">Hotel A</a></span><span class="nights">4 nights</span></div>
<div class="detail"><span class="city">Madinah</span><span class="name">Hotel name to be confirmed</span><span class="nights">3 nights</span></div>
HTML;
$cases = array(
    'issue fixture' => $fixture,
    'group lowering' => str_replace('</style>', '.detail{display:flex}</style>', $fixture),
);
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
foreach ($cases as $label => $html) {
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)));
    $plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $css = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $dom = new DOMDocument();
    @$dom->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>');
    $xpath = new DOMXPath($dom);
    $names = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," name ")]');
    if (2 !== $names->length) throw new RuntimeException($label . ': both text variants must render');
    if ('group lowering' === $label) {
        $linked = $names->item(0);
        if ('div' !== $linked->nodeName) throw new RuntimeException('Linked wrapper must exercise group lowering');
        $paragraph = $linked->getElementsByTagName('p')->item(0);
        foreach (array('font-size', 'font-weight', 'line-height', 'color', 'font-family') as $property) {
            if (!$paragraph instanceof DOMElement || !str_contains($paragraph->getAttribute('style'), $property . ':inherit')) {
                throw new RuntimeException('Synthetic carrier must inherit wrapper ' . $property . ': ' . $markup);
            }
        }
    }
    if ('' === $chrome) continue;
    $temporary = tempnam(sys_get_temp_dir(), 'synthetic-type-');
    $file = $temporary . '.html';
    rename($temporary, $file);
    try {
        // Model a theme paragraph default; source selectors and explicit
        // carrier inheritance must beat it without changing anchor styling.
        $page = '<!doctype html><style>p{font:400 16px/1.5 serif;color:black}a{color:inherit}</style><style>' . $css . '</style>' . $markup;
        $page .= '<script>const values=[...document.querySelectorAll(".name")].map(n=>{const s=getComputedStyle(n.querySelector("a")||n);return [s.fontSize,s.fontWeight,s.lineHeight,s.color,s.fontFamily]});document.body.dataset.typography=JSON.stringify(values)</script>';
        file_put_contents($file, $page);
        $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        preg_match('/data-typography="([^"]+)"/', (string) $output, $match);
        $values = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
        $expected = array('12px', '600', '17.4px', 'rgb(53, 70, 63)', 'sans-serif');
        if (array($expected, $expected) !== $values) throw new RuntimeException($label . ': text/link typography differs: ' . json_encode($values));
    } finally {
        unlink($file);
    }
}
echo "Synthetic paragraph typography contract passed.\n";
