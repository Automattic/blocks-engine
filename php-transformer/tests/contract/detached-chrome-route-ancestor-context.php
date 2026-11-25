<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Every route wraps one shared header in its own page root container
// (`.home`, `.about`, `.visit`) and defines the header's custom property on
// that container. Once the header is a template part, each route's own
// ancestor rule must still reach it, not only the representative route's.
$page = static function (string $slug, string $title): string {
    $documents = '';
    foreach (array('desktop', 'mobile') as $device) {
        $documents .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope=""><div class="site"><div class="' . $slug . ' page-root"><header class="site-header"><a class="brand" href="/">Studio</a><nav><a href="/">Home</a> <a href="/about/">About</a> <a href="/visit/">Visit</a></nav></header><main><h1>' . $title . '</h1><p>Content for ' . $title . '</p></main><footer class="site-footer"><p>&copy; Studio</p></footer></div></div></div>';
    }
    $gap = 'home' === $slug ? '20px' : '40px';
    return '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/style.css"><style>.site{color:#111}.' . $slug . '{--header-gap:' . $gap . '}.site-footer p{margin:0}</style></head><body>' . $documents . '</body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('home', 'Home'),
    'about/index.html' => $page('about', 'About'),
    'visit/index.html' => $page('visit', 'Visit'),
    'style.css' => '@media(max-width:600px){.data-blocks-engine-capture-desktop-document{display:none}}@media(min-width:601px){.data-blocks-engine-capture-mobile-document{display:none}}body{margin:0}.site-header{display:flex;gap:12px;margin-top:var(--header-gap)}.site-footer{background:#eee;padding:40px}',
)))->toArray()['source_reports']['wordpress_site_plan'];

$header = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
if ('shared_shell' !== ($header['placement']['kind'] ?? null)) throw new RuntimeException('Expected a detached shared header.');

$routeClasses = array();
foreach ($plan['pages'] as $page) $routeClasses[(string) $page['source_path']] = (string) ($page['document_metadata']['root_attributes']['class'] ?? '');

// Whether a global rule declaring $value for --header-gap applies to an
// ancestor of the header in the template, under the route's root classes.
$reaches = static function (string $rootClass, string $value) use ($plan, $header): bool {
    $document = new DOMDocument();
    $document->loadHTML('<html class="' . $rootClass . '"><body><div class="wp-site-blocks"><div class="wp-block-template-part">' . $header['canonical_block_markup'] . '</div></div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $headers = (new DOMXPath($document))->query('//header');
    if (2 !== $headers->length) throw new RuntimeException('Expected desktop and mobile header copies.');
    foreach ($headers as $target) {
        $found = false;
        foreach ($plan['assets'] as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || 'global' !== ($asset['scopes'][0]['kind'] ?? null)) continue;
            (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body) use ($target, $value, &$found): void {
                if (!preg_match('/(?:^|;)\s*--header-gap\s*:\s*' . preg_quote($value, '/') . '\s*(?:;|$)/', $body)) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    // The source matcher does not implement :has(). Resolve the
                    // generated direct-child carrier against this saved tree.
                    $selector = preg_replace_callback('/(?::where\()?:has\(> (\.[\w.-]+|#[\w-]+)\)\)?/', static function (array $match) use ($target): string {
                        for ($parent = $target->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                            foreach ($parent->childNodes as $child) {
                                if ($child instanceof DOMElement && CssSelectorMatcher::matches($child, CssSelectorMatcher::parse($match[1]), true)['matches']) $parent->setAttribute('data-test-carrier', 'yes');
                            }
                        }
                        return '[data-test-carrier="yes"]';
                    }, $selector) ?? $selector;
                    $parsed = CssSelectorMatcher::parse($selector);
                    for ($element = $target; $element instanceof DOMElement; $element = $element->parentNode) {
                        if (CssSelectorMatcher::matches($element, $parsed, true)['matches']) $found = true;
                    }
                }
            });
        }
        if (!$found) return false;
    }
    return true;
};

foreach (array('about/index.html', 'visit/index.html') as $route) {
    if (!$reaches($routeClasses[$route], '40px')) throw new RuntimeException($route . ' lost the header custom property its page root defined.');
}
if ($reaches($routeClasses['index.html'], '40px')) throw new RuntimeException('Another route\'s page root rule must not reach the header on the home route.');
echo "Detached chrome route ancestor context passed.\n";
