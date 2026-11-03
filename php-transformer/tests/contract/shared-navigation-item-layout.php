<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$source = file_get_contents(__DIR__ . '/fixtures/shared-navigation-item-layout.html');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(1 === count($plan['menus']), 'Identical menus share one entity.');
$css = '';
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'];
$hosts = array();
foreach (array_merge($plan['pages'], $plan['template_parts']) as $document) {
    preg_match_all('/<!-- wp:navigation (\{.*?\}) \/-->/', $document['canonical_block_markup'], $matches);
    foreach ($matches[1] as $json) $hosts[] = json_decode($json, true);
}
$assert(2 === count($hosts), 'Both placements reference the shared menu.');
$html = '<html><body>';
foreach ($hosts as $host) {
    $assert(str_contains($host['ref'], $plan['menus'][0]['token']), 'Each host uses the same entity.');
    $html .= '<nav class="wp-block-navigation ' . htmlspecialchars($host['className'] ?? '') . '"><ul class="wp-block-navigation__container">';
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $plan['menus'][0]['block_markup'], $items);
    foreach ($items[1] as $json) {
        $attrs = json_decode($json, true);
        $html .= '<li class="wp-block-navigation-item ' . htmlspecialchars($attrs['className'] ?? '') . '"><a class="wp-block-navigation-item__content">' . $attrs['label'] . '</a></li>';
    }
    $html .= '</ul></nav>';
}
$html .= '</body></html>';
$dom = new DOMDocument();
@$dom->loadHTML($html);
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
$fullWidth = array();
foreach ($dom->getElementsByTagName('nav') as $nav) {
    $matches = array();
    foreach ($nav->getElementsByTagName('li') as $item) {
        $matched = false;
        foreach ($rules as $rule) {
            if (!preg_match('/(?:^|;)\s*width:\s*100%\s*(?:;|$)/', $rule[2])) continue;
            foreach (CssStylesheetTransformer::splitSelectorList($rule[1]) ?? array() as $selector) {
                $parsed = CssSelectorMatcher::parse(trim($selector));
                if ($parsed['supported'] && (CssSelectorMatcher::matches($item, $parsed)['matches'] ?? false)) $matched = true;
            }
        }
        $matches[] = $matched;
    }
    $fullWidth[] = $matches;
}
sort($fullWidth);
$assert(array(array(false, false, false), array(true, true, true)) === $fullWidth, 'Only the mobile placement receives full-width item rules after entity consolidation.');
// Measure Core's host/container/item shape with the compiled assets, never
// with the source stylesheet. The selector check above also runs without Chrome.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $temp = tempnam(sys_get_temp_dir(), 'shared-nav-');
    $file = $temp . '.html';
    unlink($temp);
    $browserHtml = str_replace('<html>', '<html><head><style>.wp-block-navigation{display:flex;width:350px}.wp-block-navigation__container{display:contents;width:100%}.wp-block-navigation-item{display:flex;flex-shrink:0}' . $css . '</style></head>', $html);
    $browserHtml .= '<script>document.body.dataset.widths=JSON.stringify([...document.querySelectorAll("nav")].map(n=>[...n.querySelectorAll("li")].map(i=>i.getBoundingClientRect().width)).sort((a,b)=>a[0]-b[0]));</script>';
    file_put_contents($file, $browserHtml);
    try {
        $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --window-size=390,900 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        preg_match('/data-widths="([^"]+)"/', (string) $output, $measured);
        $widths = json_decode(html_entity_decode($measured[1] ?? '', ENT_QUOTES), true);
        $assert(array(array(90, 90, 90), array(350, 350, 350)) === $widths, 'At phone width only mobile items fill the 350px row: ' . json_encode($widths));
    } finally { unlink($file); }
}
echo "shared-navigation-item-layout: ok\n";
