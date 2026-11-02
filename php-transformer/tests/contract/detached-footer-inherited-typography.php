<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$page = static function (string $title): string {
    $documents = '';
    foreach (array('desktop', 'mobile') as $device) {
        $documents .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope=""><div class="bg"></div><div class="body"><main><h1>' . $title . '</h1><p>Content for ' . $title . '</p></main><footer class="site-footer"><p>&copy; Studio</p>' . ('desktop' === $device ? '<p>Open daily</p>' : '') . '</footer></div></div>';
    }
    return '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>' . $documents . '</body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home'),
    'about/index.html' => $page('About'),
    'visit/index.html' => $page('Visit'),
    'style.css' => 'body{margin:0}.bg{position:absolute;inset:0;background:#fff}.body{position:relative;font-family:Georgia,serif;letter-spacing:2px;text-transform:uppercase;padding:30px;max-width:900px}.site-footer{background:#eee;padding:40px}@media(max-width:600px){.data-blocks-engine-capture-desktop-document{display:none}}@media(min-width:601px){.data-blocks-engine-capture-mobile-document{display:none}}',
)))->toArray()['source_reports']['wordpress_site_plan'];
$footer = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
if ('shared_shell' !== ($footer['placement']['kind'] ?? null) || !in_array('body', $footer['ancestor_context']['classes'] ?? array(), true)) throw new RuntimeException('Expected a detached footer with retained ancestor context.');
$document = new DOMDocument();
$document->loadHTML('<html><body><div class="wp-site-blocks"><div class="wp-block-template-part">' . $footer['canonical_block_markup'] . '</div></div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$texts = $xpath->query('//footer/p[1]');
if (2 !== $texts->length) throw new RuntimeException('Expected desktop and mobile copyright text.');
// Check the emitted selectors against saved markup, not just block attributes.
// A declaration on any actual ancestor supplies the text's inherited value.
foreach ($texts as $text) {
    foreach (array('font-family' => 'Georgia,serif', 'letter-spacing' => '2px', 'text-transform' => 'uppercase') as $property => $value) {
        $found = false;
        foreach ($plan['assets'] as $asset) {
            if ('css' !== ($asset['kind'] ?? null)) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($text, $property, $value, &$found): void {
                if (!preg_match('/' . preg_quote($property, '/') . ':(?:var\([^;{}]*,)?' . preg_quote($value, '/') . '(?:\))?(?:;|$)/', $body)) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    // The source matcher does not implement :has(). Resolve the
                    // generated direct-child carrier against this saved tree.
                    $selector = preg_replace_callback('/(?::where\()?:has\(> (\.[\w.-]+|#[\w-]+)\)\)?/', static function (array $match) use ($text): string {
                        for ($parent = $text->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                            foreach ($parent->childNodes as $child) {
                                if ($child instanceof DOMElement && CssSelectorMatcher::matches($child, CssSelectorMatcher::parse($match[1]), true)['matches']) {
                                    $parent->setAttribute('data-test-carrier', 'yes');
                                }
                            }
                        }
                        return '[data-test-carrier="yes"]';
                    }, $selector) ?? $selector;
                    $parsed = CssSelectorMatcher::parse($selector);
                    for ($element = $text; $element instanceof DOMElement; $element = $element->parentNode) {
                        if (CssSelectorMatcher::matches($element, $parsed, true)['matches']) $found = true;
                    }
                }
                if (str_contains($prelude, ':has(') && (str_contains($body, 'padding:30px') || str_contains($body, 'max-width:900px'))) throw new RuntimeException('Ancestor box declarations must not follow the part.');
            });
        }
        if (!$found) throw new RuntimeException('Footer text lost inherited ' . $property . '.');
    }
}
echo "Detached footer inherited typography passed.\n";
