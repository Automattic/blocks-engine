<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Each route captures a desktop and a mobile document whose footer rules are
// gated to that device. The shared footer part holds both copies, so each
// copy must keep the device gate its rules and visibility CSS select.
$page = file_get_contents(dirname(__DIR__) . '/fixtures/device-gated-shared-footer.html');
$assert(is_string($page) && '' !== $page, 'Missing device-gated shared footer fixture.');
$route = static fn(string $title): string => str_replace(array('<title>Home</title>', '<h1>Sample page</h1>'), array('<title>' . $title . '</title>', '<h1>' . $title . ' page</h1>'), $page);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $route('Home'),
    'about/index.html' => $route('About'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)));
$assert(1 === count($footers), 'Both routes share one footer part.');
$markup = $footers[0]['canonical_block_markup'];
foreach ($plan['pages'] as $planPage) $assert(!str_contains($planPage['canonical_block_markup'], 'Example Name'), 'The footer is not duplicated in page content.');

// A dynamic navigation block renders its className on the server.
$rendered = preg_replace_callback('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', static function (array $match): string {
    $attrs = json_decode($match[1], true);
    return '<nav class="wp-block-navigation ' . htmlspecialchars((string) ($attrs['className'] ?? ''), ENT_QUOTES) . '"></nav>';
}, $markup) ?? $markup;
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8"?><html><body><div class="wp-site-blocks"><div class="wp-block-template-part">' . $rendered . '</div></div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
foreach (array('desktop', 'mobile') as $device) {
    $wrappers = $xpath->query('//*[@' . EngineMarker::DEVICE_SCOPE_ATTRIBUTE . '="' . $device . '"][@' . CaptureEvidence::DEVICE_DOCUMENT . '="' . $device . '"]');
    $assert(1 === $wrappers->length, 'The ' . $device . ' footer copy keeps its device document gate.');
    $assert(1 === $xpath->query('.//footer', $wrappers->item(0))->length, 'The ' . $device . ' gate encloses its own footer copy.');
}
$assert(!str_contains($markup, CaptureEvidence::DOCUMENT_SCOPE), 'The part does not become another capture document root.');

// Every device-gated footer rule (placement and font token) still reaches the
// saved part markup, under the gate it was authored for.
$probes = array('calc(50% - 490px)', '--footer-note-font:', 'font:var(--footer-note-font)');
$reached = array_fill_keys($probes, 0);
foreach ($plan['assets'] as $asset) {
    // Editor-only anchor repairs are guarded to subjects whose saved id the
    // editor lost, so they intentionally do not match the saved markup here.
    if ('css' !== ($asset['kind'] ?? null) || 'editor' === ($asset['stylesheet_target'] ?? null)) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($probes, $xpath, $assert, &$reached): void {
        foreach ($probes as $probe) {
            if (!str_contains($body, $probe)) continue;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                if (!preg_match('/\[' . preg_quote(EngineMarker::DEVICE_SCOPE_ATTRIBUTE, '/') . '="(desktop|mobile)"\]/', $selector)) continue;
                $parsed = CssSelectorMatcher::parse($selector);
                $matched = false;
                foreach ($xpath->query('//*') as $element) {
                    if ($element instanceof DOMElement && CssSelectorMatcher::matches($element, $parsed, true)['matches']) { $matched = true; break; }
                }
                $assert($matched, 'Device-gated footer rule reaches the shared part: ' . $selector . '{' . $body . '}');
                ++$reached[$probe];
            }
        }
    });
}
foreach ($reached as $probe => $count) $assert(0 < $count, 'The plan emits a device-gated footer rule for ' . $probe . '.');

echo "device-gated-shared-footer: passed\n";
