<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Issue #6063: type selectors must never acquire a synthetic RichText host.
$html = <<<'HTML'
<!doctype html><html><head><style>body{margin:0}.fab{position:fixed;z-index:80;right:18px;bottom:20px;display:flex;flex-direction:column;gap:11px;align-items:flex-end}.fab-link{display:flex;align-items:center;justify-content:center;gap:9px;min-width:54px;height:54px;padding:0 16px;border-radius:999px;color:#fff;box-shadow:0 8px 24px rgba(6,30,24,.27);transition:transform .2s,box-shadow .2s}.fab-link:hover{transform:translateY(-3px)}.fab-link svg{width:24px;height:24px;flex:none}.fab-link span{font-size:11px;font-weight:700;letter-spacing:.015em}.fab-chat{background:#168c54}@media (max-width:600px){.fab{right:14px;bottom:14px;gap:9px}.fab-link{width:50px;height:50px;min-width:50px;padding:0}.fab-link span{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.fab-link svg{width:22px;height:22px}}@media print{.fab{display:none!important}}</style></head>
<body><main><p>Content</p></main><div class="fab" aria-label="Quick contact"><a class="fab-link fab-chat" href="https://example.com/chat?text=Hello" target="_blank" rel="noopener noreferrer" aria-label="Chat with us on the messaging app"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2 2h20v20H2z"/></svg><span>Chat with us</span></a></div></body></html>
HTML;
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);
$anchor = $xpath->query('//a[@href="https://example.com/chat?text=Hello"]')->item(0);
$assert($anchor instanceof DOMElement, 'The source anchor survives compilation.');
$children = $xpath->query('./*', $anchor);
$assert(2 === $children->length, 'The anchor must have two direct flex children, not a synthetic wrapper.');
$icon = $children->item(0);
$label = $children->item(1);
$assert($icon instanceof DOMElement && 'img' === $icon->tagName, 'The materialized SVG is the first direct child.');
$assert($label instanceof DOMElement && 'span' === $label->tagName && 'Chat with us' === $label->textContent, 'The original label span remains the second direct child.');
$assert(0 === $xpath->query('./ancestor::span', $icon)->length, 'No span can clip the icon.');

$frontend = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        $frontend .= (string) ($asset['content'] ?? '') . "\n";
    }
}
$assert(str_contains($frontend, 'max-width:600px') || str_contains($frontend, 'max-width: 600px'), 'The responsive author rules are emitted.');
$hiddenTargets = array();
$flex = false;
$gap = false;
(new CssStylesheetTransformer())->transformStyleRules($frontend, static function (string $prelude, string $body) use ($anchor, $icon, $label, &$hiddenTargets, &$flex, &$gap): string {
    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
        if (str_contains($selector, '.editor-styles-wrapper')) {
            continue;
        }
        $parsed = CssSelectorMatcher::parse(trim($selector));
        if (CssSelectorMatcher::matches($anchor, $parsed)['matches']) {
            $flex = $flex || str_contains($body, 'display:flex');
            $gap = $gap || str_contains($body, 'gap:9px');
        }
        if (str_contains($body, 'clip:rect(')) {
            foreach (array('anchor' => $anchor, 'icon' => $icon, 'label' => $label) as $name => $element) {
                if (CssSelectorMatcher::matches($element, $parsed)['matches']) {
                    $hiddenTargets[$name] = true;
                }
            }
        }
    }
    return $prelude . '{' . $body . '}';
});
$assert($flex && $gap, 'Emitted author rules keep the anchor a flex container with its source gap.');
$assert(array('label' => true) === $hiddenTargets, 'The responsive visually-hidden span rule matches only the label, never the icon or its host.');

// The generated editor/save implementation must agree with the PHP markup.
$assert(str_contains($markup, '"contentMode":"inline-rich-text"'), 'Wrapper-free content uses the anchor as its RichText host.');
echo "anchor direct RichText children contract passed.\n";
