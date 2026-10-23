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
    // The anchor's surface is moved onto the link, never duplicated on the
    // core/button and core/buttons wrappers that core saves around it.
    $wrappers = array();
    for ($node = $link->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
        if (preg_match('/\bwp-block-buttons?\b/', $node->getAttribute('class'))) $wrappers[] = $node;
    }
    $assert(2 === count($wrappers), 'The link sits inside generated core/button and core/buttons wrappers.');
    $inline = $xpath->query('//a[@href="#details"]')->item(0);
    $assert($inline instanceof DOMElement && !str_contains($inline->getAttribute('class'), 'wp-block-button__link'), 'The same-scoped inline link stays a plain link sharing the attribute marker.');
    $surface = static function (string $name): ?string {
        foreach (array('background', 'padding', 'border') as $family) {
            if ($name === $family || str_starts_with($name, $family . '-')) return 'border' === $family && str_contains($name, 'radius') ? 'border-radius' : $family;
        }
        return null;
    };
    $neutral = static fn (string $value): bool => 1 === preg_match('/^(?:0(?:px)?|none|transparent|initial|unset)(?:\s+(?:0(?:px)?|none))*(?:\s*!important)?$/i', trim($value));
    $linkSurface = array('background' => 0, 'padding' => 0, 'border' => 0, 'border-radius' => 0, 'color' => 0);
    $inlineSurface = $linkSurface;
    $wrapperSurface = array();
    foreach ($wrappers as $wrapper) {
        foreach (explode(';', $wrapper->getAttribute('style')) as $declaration) {
            $name = strtolower(trim((string) strstr($declaration, ':', true)));
            if (null !== $surface($name)) $wrapperSurface[] = 'inline ' . trim($declaration);
        }
    }
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '') || !in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) continue;
        (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use ($link, $inline, $wrappers, $surface, $neutral, &$linkSurface, &$inlineSurface, &$wrapperSurface): string {
            $declarations = array();
            foreach (explode(';', $body) as $declaration) {
                if (false === strpos($declaration, ':')) continue;
                $declarations[] = array(strtolower(trim((string) strstr($declaration, ':', true))), trim(substr($declaration, strpos($declaration, ':') + 1)));
            }
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                if (str_contains($selector, '.editor-styles-wrapper')) continue;
                $parsed = CssSelectorMatcher::parse($selector);
                $onLink = CssSelectorMatcher::matches($link, $parsed)['matches'];
                $onInline = CssSelectorMatcher::matches($inline, $parsed)['matches'];
                $onWrapper = false;
                foreach ($wrappers as $wrapper) $onWrapper = $onWrapper || CssSelectorMatcher::matches($wrapper, $parsed)['matches'];
                foreach ($declarations as [$name, $value]) {
                    $family = 'color' === $name ? 'color' : $surface($name);
                    if (null === $family || !preg_match('/(?:var|calc)\(/', $value)) continue;
                    if ($onLink) ++$linkSurface[$family];
                    if ($onInline) ++$inlineSurface[$family];
                    if ($onWrapper && 'color' !== $family && !$neutral($value)) $wrapperSurface[] = trim($selector) . ' { ' . $name . ':' . $value . ' }';
                }
            }
            return $prelude . '{' . $body . '}';
        });
    }
    foreach ($linkSurface as $family => $count) $assert($count > 0, "Author $family reaches the link.");
    foreach ($inlineSurface as $family => $count) $assert($count > 0, "Author $family still reaches the plain inline link.");
    $assert(array() === $wrapperSurface, "Generated wrappers carry none of the anchor's surface:\n" . implode("\n", $wrapperSurface));
    $assert(1 === preg_match('/--background-color:[^;]*rgb\(125,28,40\)/', $markup . implode('', array_column($plan['assets'], 'content'))), 'Inline custom properties remain available to the projected rule.');
}
echo "Data-scoped button surface passed.\n";
