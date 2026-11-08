<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A list-item button gains two Core wrappers between its li and anchor.
$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Btn</title><style>
.btns{display:flex;flex-flow:column;padding:0;margin:0;list-style:none;width:320px}
.btns>li>a{display:inline-flex;width:100%;flex-direction:row-reverse;padding:12px 20px;background:#f05e3e;color:#fff;text-decoration:none}
.btns>li>a>svg{display:block;width:24px;height:24px;fill:#fff;flex-shrink:0}
.btns>li>a>.label{flex-grow:1;text-align:left;width:100%}
</style></head><body><main>
<ul class="btns"><li><a href="#about" role="button"><svg viewBox="0 0 40 40"><title>Chevron</title><path d="M17 5l13 14-13 14-3-3 11-11-11-11z"></path></svg><span class="label">About</span></a></li></ul>
</main></body></html>
HTML;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(str_contains($markup, '<!-- wp:buttons') && str_contains($markup, '<!-- wp:button '), 'The fixture must lower to native button wrappers.');
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);
$label = $xpath->query('//a//span[contains(concat(" ", normalize-space(@class), " "), " label ")]')->item(0);
$icon = $xpath->query('//a//img | //a//svg')->item(0);
$assert($label instanceof DOMElement && $icon instanceof DOMElement, 'The button retains both flex items.');
$matched = array('label' => '', 'icon' => '');
foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? null) || !in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) continue;
    (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $prelude, string $body) use ($label, $icon, &$matched): void {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            if (str_contains($selector, '.editor-styles-wrapper')) continue;
            foreach (array('label' => $label, 'icon' => $icon) as $name => $element) {
                if (CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector))['matches']) $matched[$name] .= $body . ';';
            }
        }
    });
}
$assert(1 === preg_match('/(?:^|;)\s*flex-grow\s*:\s*1\s*(?:;|$)/', $matched['label']), 'The authored flex-grow rule must reach the emitted label.');
$assert(1 === preg_match('/(?:^|;)\s*width\s*:\s*100%\s*(?:;|$)/', $matched['label']), 'The authored full-width rule must reach the emitted label.');
$assert(1 === preg_match('/(?:^|;)\s*width\s*:\s*24px\s*(?:;|$)/', $matched['icon']), 'The same projection must reach the icon, independently of label classes.');
$assert(!str_contains(json_encode($result['diagnostics'] ?? array(), JSON_THROW_ON_ERROR), 'dead_projected_author_selector'), 'The reduced page must not report dead projected author selectors.');
echo "Button list descendant selectors contract passed.\n";
