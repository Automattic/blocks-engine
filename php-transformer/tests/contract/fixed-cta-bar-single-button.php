<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// A fixed bottom CTA bar with its own paint and responsive visibility wraps a
// single button-like link. The bar must stay a group carrying its own paint
// and its breakpoint hiding, and the link must become the core/button with
// the button's own paint — not the bar's.
$fixture = require dirname(__DIR__) . '/fixtures/fixed-bottom-cta-bar.php';
$result = (new ArtifactCompiler())->compile($fixture)->toArray();
$assert('success' === ($result['status'] ?? ''), 'The fixture compiles.');
$markup = (string) ($result['serialized_blocks'] ?? '');

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);
$hasClass = static fn (string $class): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
$bar = $xpath->query('//*[' . $hasClass('wp-block-group') . ' and ' . $hasClass('bar') . ']')->item(0);
$assert($bar instanceof DOMElement, 'The bar remains a group carrying the source bar class.');
$link = $xpath->query('.//*[' . $hasClass('wp-block-button__link') . ']', $bar)->item(0);
$assert($link instanceof DOMElement, 'The link inside the bar remains a core/button link.');
$assert(str_contains($link->getAttribute('style'), 'background-color:#3a6e9e'), 'The link carries its own button paint.');
$assert(!str_contains($link->getAttribute('style'), 'rgba(248,245,241,.9)'), 'The bar paint does not land on the link.');

$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'] . "\n";
}

// The bar paint reaches the group, never the link.
$paintReachesGroup = false;
(new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use ($assert, $bar, $link, &$paintReachesGroup): string {
    if (str_contains($body, 'rgba(248,245,241,.9)')) {
        foreach (CssStylesheetTransformer::splitSelectorList(trim($prelude)) ?? array() as $selector) {
            $linkMatch = CssSelectorMatcher::matches($link, CssSelectorMatcher::parse(trim($selector)));
            $assert(!($linkMatch['supported'] && $linkMatch['matches']), 'No bar-paint selector reaches the link: ' . $selector);
            $barMatch = CssSelectorMatcher::matches($bar, CssSelectorMatcher::parse(trim($selector)));
            if ($barMatch['supported'] && $barMatch['matches']) $paintReachesGroup = true;
        }
    }
    return $prelude . '{' . $body . '}';
});
$assert($paintReachesGroup, 'A bar-paint rule matches the group carrying the bar class.');

// The breakpoint hiding survives and lands on the bar group.
$hiddenAboveBreakpoint = false;
preg_match_all('/@media\s*\(min-width:\s*640px\)\s*\{((?:[^{}]*\{[^{}]*\})+)\}/', $css, $mediaBlocks, PREG_SET_ORDER);
foreach ($mediaBlocks as $mediaBlock) {
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $mediaBlock[1], $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (!preg_match('/(?:^|;)\s*display\s*:\s*none\s*(?:;|$)/', $rule[2])) continue;
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $barMatch = CssSelectorMatcher::matches($bar, CssSelectorMatcher::parse(trim($selector)));
            if ($barMatch['supported'] && $barMatch['matches']) $hiddenAboveBreakpoint = true;
        }
    }
}
$assert($hiddenAboveBreakpoint, 'A min-width:640px display:none rule matches the bar group.');

// Match source and compiled computed styles in the browser.
$chrome = getenv('CHROME_PATH') ?: (is_executable('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome') ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : trim((string) shell_exec('command -v chromium || command -v google-chrome || true')));
if ('' !== $chrome) {
    $probe = '<script>addEventListener("load",()=>{const bar=document.querySelector(".bar");const link=document.querySelector(".wp-block-button__link")||document.querySelector(".bar a");document.body.dataset.probe=JSON.stringify({barBg:getComputedStyle(bar).backgroundColor,barDisplay:getComputedStyle(bar).display,linkBg:link?getComputedStyle(link).backgroundColor:""});});</script>';
    $sourcePage = str_replace('</head>', '<style>' . $fixture['files']['site.css'] . '</style></head>', (string) preg_replace('/<link[^>]*>/', '', (string) $fixture['files']['index.html']));
    $pages = array(
        'source'   => str_replace('</body>', $probe . '</body>', $sourcePage),
        'compiled' => '<!doctype html><html><head><style>' . $css . '</style></head><body>' . $markup . $probe . '</body></html>',
    );
    $temp = tempnam(sys_get_temp_dir(), 'fixed-cta-bar-');
    $file = $temp . '.html';
    unlink($temp);
    $measured = array();
    try {
        foreach (array(390, 1440) as $width) {
            foreach ($pages as $kind => $page) {
                file_put_contents($file, $page);
                // TMPDIR may point at a deep working directory whose path
                // exceeds Chrome's singleton-socket length limit.
                $output = shell_exec('TMPDIR=/tmp ' . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --window-size=' . $width . ',900 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
                preg_match('/data-probe="([^"]+)"/', (string) $output, $probeMatch);
                $measured[$width][$kind] = json_decode(html_entity_decode($probeMatch[1] ?? '', ENT_QUOTES), true);
            }
        }
    } finally {
        unlink($file);
    }
    foreach (array(390, 1440) as $width) {
        $assert(is_array($measured[$width]['source'] ?? null) && is_array($measured[$width]['compiled'] ?? null), 'Browser measured source and compiled pages at ' . $width . '.');
    }
    $assert('rgba(248, 245, 241, 0.9)' === $measured[390]['source']['barBg'], 'The source bar paints cream at 390.');
    $assert('rgb(58, 110, 158)' === $measured[390]['source']['linkBg'], 'The source link paints its own background at 390.');
    $assert('none' === $measured[1440]['source']['barDisplay'], 'The source bar is hidden at 1440.');
    foreach (array('barBg', 'linkBg') as $key) {
        $assert($measured[390]['source'][$key] === $measured[390]['compiled'][$key], 'The compiled ' . $key . ' matches the source at 390: ' . json_encode($measured[390]));
    }
    $assert($measured[1440]['source']['barDisplay'] === $measured[1440]['compiled']['barDisplay'], 'The compiled bar visibility matches the source at 1440: ' . json_encode($measured[1440]));
    $assert('none' === $measured[1440]['compiled']['barDisplay'], 'The compiled bar group is hidden at 1440.');
}

echo "Fixed CTA bar single button passed\n";
