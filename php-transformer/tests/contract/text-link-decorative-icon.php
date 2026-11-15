<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// Issue #6117: the painted icon is not the anchor's control surface.
$icon = ' <span><span class="icon" aria-hidden="true"></span></span>';
$variants = array(
    'plain text links' => array('/g/' => 'Read the guides', '/c/' => 'Compare'),
    'two icon links' => array('/g/' => 'Read the guides' . $icon, '/c/' => 'Compare' . $icon),
    'one icon link' => array('/g/' => 'Read the guides' . $icon),
    'pill only' => array(),
);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
foreach ($variants as $name => $links) {
    $html = '<style>
.links{display:flex;flex-wrap:wrap;gap:11px 22px}
.links a{color:#064c3b;font-size:11px;font-weight:700;text-decoration:underline;text-decoration-color:#c9a227;text-underline-offset:5px}
.links a span{padding-left:4px}
.icon{display:inline-block;width:16px;height:16px;border-radius:50%;background:#c9a227}
.button{display:inline-flex;padding:12px 20px;border-radius:999px;background:#c9a227}
</style><section><p>Intro copy.</p><div class="links">';
    foreach ($links as $href => $label) {
        $html .= '<a href="' . $href . '">' . $label . '</a>';
    }
    $html .= '</div><a class="button" href="/q/">Ask us' . $icon . '</a></section>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(1 === substr_count($markup, '<!-- wp:button '), $name . ': only the pill becomes core/button');
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $markup . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $pill = $xpath->query('//a[@href="/q/"]')->item(0);
    $assert($pill instanceof DOMElement && str_contains($pill->getAttribute('class'), 'wp-block-button__link'), $name . ': pill keeps the button surface');
    $css = '';
    foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? '') && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
            $css .= $asset['content'] . "\n";
        }
    }
    foreach ($links as $href => $label) {
        $anchor = $xpath->query('//a[@href="' . $href . '"]')->item(0);
        $assert($anchor instanceof DOMElement, $name . ': text link keeps ' . $href);
        $assert(! str_contains($anchor->getAttribute('class'), 'wp-block-button__link'), $name . ': text link is not a button');
        $assert(0 === $xpath->query('ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " blocks-engine-role-control ")]', $anchor)->length, $name . ': decoration does not assign the control role');
        $assert(trim(strip_tags($label)) === trim($anchor->textContent), $name . ': label survives');
        if (str_contains($label, 'class="icon"')) {
            $assert(1 === $xpath->query('.//span[@class="icon" and @aria-hidden="true"]', $anchor)->length, $name . ': decorative icon span survives');
        }
        $declarations = SourceDeclarations::inline($anchor);
        (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $selectorList, string $body) use ($anchor, &$declarations): string {
            foreach (CssStylesheetTransformer::splitSelectorList($selectorList) ?? array() as $selector) {
                if (str_contains($selector, '.editor-styles-wrapper')) {
                    continue;
                }
                $match = CssSelectorMatcher::matches($anchor, CssSelectorMatcher::parse($selector));
                if ($match['supported'] && $match['matches']) {
                    $declarations = array_merge($declarations, SourceDeclarations::parse($body));
                    break;
                }
            }
            return $selectorList . '{' . $body . '}';
        });
        foreach (array('color' => '#064c3b', 'font-size' => '11px', 'font-weight' => '700', 'text-decoration' => 'underline', 'text-decoration-color' => '#c9a227', 'text-underline-offset' => '5px') as $property => $value) {
            $actual = $declarations[$property] ?? '';
            $assert($actual === $value || str_contains(str_replace(' ', '', $actual), ',' . $value . ')'), $name . ': preserves ' . $property . ' (got ' . $actual . ')');
        }
    }
}
echo "text link decorative icon contract passed.\n";
