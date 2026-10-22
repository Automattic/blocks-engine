<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/data-scoped-button-surface.html');
// Exercise the complete artifact/site-plan path used by the importer, both a
// standalone control and one participating directly in an authored flex layout.
foreach (array(false, true) as $flex) {
    $source = $flex ? str_replace(array('<body>', '</body>'), array('<body><div style="display:flex">', '</div></body>'), $html) : $html;
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $markup = implode('', array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
    $assert(str_contains($markup, '<!-- wp:button '), 'The anchor becomes an editable core/button.');
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $markup . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $link = $xpath->query('//a[@href="#contact"]')->item(0);
    $assert($link instanceof DOMElement, 'The emitted link exists.');
    $assert(str_contains($link->getAttribute('class'), 'wp-block-button__link'), 'The native button link is the control surface.');
    $assert(!str_contains($link->getAttribute('style'), 'background-color:'), 'Responsive paint stays stylesheet-owned: an inline background must not mask a lost projection.');
    $hits = array('background-color' => 0, 'border-radius' => 0, 'padding' => 0, 'color' => 0);
    $wrapperPaint = 0;
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '') || !in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) continue;
        (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use ($link, &$hits, &$wrapperPaint): string {
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                if (str_contains($selector, '.editor-styles-wrapper')) continue;
                $parsed = CssSelectorMatcher::parse($selector);
                $matchesLink = CssSelectorMatcher::matches($link, $parsed)['matches'];
                foreach ($hits as $property => $count) {
                    if (preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*(?:var|calc)\(/', $body) && $matchesLink) ++$hits[$property];
                }
                if (str_contains($body, 'background-color:var(--background-color,')) {
                    for ($wrapper = $link->parentNode; $wrapper instanceof DOMElement; $wrapper = $wrapper->parentNode) {
                        if (CssSelectorMatcher::matches($wrapper, $parsed)['matches']) ++$wrapperPaint;
                    }
                }
            }
            return $prelude . '{' . $body . '}';
        });
    }
    foreach ($hits as $property => $count) $assert($count > 0, "Author $property reaches the link, not just its wrappers.");
    $assert(0 === $wrapperPaint, 'Neither generated wrapper paints the author background.');
    $assert(1 === preg_match('/--background-color:[^;]*rgb\(125,28,40\)/', $markup . implode('', array_column($plan['assets'], 'content'))), 'Inline custom properties remain available to the projected rule.');
}
echo "Data-scoped button surface passed.\n";
