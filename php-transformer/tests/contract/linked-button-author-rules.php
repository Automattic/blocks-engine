<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$plan = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/linked-button-author-rules.php')->toArray()['source_reports']['wordpress_site_plan'];
$markup = implode('', array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
$css = '';
foreach ($plan['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? '') && in_array($asset['stylesheet_target'] ?? 'both', array('both', 'frontend'), true)) $css .= $asset['content'] . "\n";
}
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);
foreach (array('none', 'plain', 'classed') as $variant) {
    $link = $xpath->query('//a[@href="#' . $variant . '"]')->item(0);
    $assert($link instanceof DOMElement, "$variant: link exists");
    $classes = explode(' ', $link->parentNode->getAttribute('class'));
    foreach (array('btn', 'h-9', 'bg-white', 'hover:bg-blue-50') as $class) {
        $assert(in_array(EngineMarker::subjectClassClass($class), $classes, true), "$variant: source carrier for $class");
    }
    $assert(!str_contains($link->getAttribute('style'), 'background-color:'), "$variant: background is stylesheet-owned, not an inline literal");
    $height = false;
    $hover = false;
    (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use ($link, &$height, &$hover): string {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            if (str_contains($selector, '.editor-styles-wrapper')) continue;
            // Match the hover subject structurally; browser coverage checks the state.
            $match = CssSelectorMatcher::matches($link, CssSelectorMatcher::parse(str_replace(':hover', '', trim($selector))));
            if ($match['supported'] && $match['matches']) {
                if (str_contains($body, 'height:2.25rem')) $height = true;
                if (str_contains($selector, ':hover') && str_contains($body, 'background-color:#eff6ff')) $hover = true;
            }
        }
        return $prelude . '{' . $body . '}';
    });
    $assert($height, "$variant: height rule reaches the link");
    $assert($hover, "$variant: hover background reaches the link");
    $assert('Go' === trim($link->firstChild->textContent ?? ''), "$variant: label stays in link");
    $assert(('none' === $variant ? 0 : 1) === $xpath->query('./img', $link)->length, "$variant: icon stays beside label");
}
$assert(0 === $document->getElementsByTagName('button')->length, 'No nested interactive button remains');
echo "Linked button author rules passed.\n";
