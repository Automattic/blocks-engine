<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$links = '<li><a href="/about/">About Us</a><ul class="sf-hidden"><li><a href="/about/chair/">Chair</a></li></ul></li><li><a href="/people/">People</a></li><li><a href="/research/">Research</a></li>';
foreach ( array(
    'mobile-only empty toggle' => '<div class="sf-accordion-toggle data-blocks-engine-capture-mobile-only"></div>',
    'empty toggle' => '<div class="sf-accordion-toggle"></div>',
    'no toggle' => '',
) as $variant => $toggle ) {
    $html = '<style>@media(min-width:781px){.data-blocks-engine-capture-mobile-only{display:none!important}}'
        . '@media(max-width:780px){#menu-desktop{display:none!important}}</style>'
        . '<div id="block-menu" class="block">' . $toggle
        . '<ul id="menu-desktop" class="menu sf-horizontal">' . $links . '</ul>'
        . '<ul id="menu-accordion" class="menu sf-accordion data-blocks-engine-capture-mobile-only">' . $links . '</ul></div>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(1 === substr_count($markup, '<!-- wp:navigation '), $variant . ': one navigation');
    preg_match('/<!-- wp:navigation (.*?) -->/', $markup, $match);
    $attrs = json_decode($match[1] ?? '{}', true);
    $assert('never' === ($attrs['overlayMenu'] ?? null), $variant . ': the mobile list stays visible without a toggle');

    // Match projected author rules against Core's kept navigation host, not
    // the original UL: a leftover #menu-desktop rule alone is harmless.
    $document = new DOMDocument();
    $document->loadHTML('<html><body><div id="block-menu" class="wp-block-group block">'
        . '<nav class="wp-block-navigation '
        . htmlspecialchars($attrs['className'] ?? '') . '"></nav></div></body></html>');
    $navigation = $document->getElementsByTagName('nav')->item(0);
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selectorList, string $body, array $conditions) use ($assert, $variant, $navigation): void {
        $conditions = array_map(static fn ($condition): string => str_replace(' ', '', (string) $condition), $conditions);
        if ( ! in_array('@media(max-width:780px)', $conditions, true)
            || 'none' !== CssValueInspector::declared(SourceDeclarations::parse($body), 'display') ) {
            return;
        }
        foreach ( CssStylesheetTransformer::splitSelectorList($selectorList) ?? array() as $selector ) {
            $match = CssSelectorMatcher::matches($navigation, CssSelectorMatcher::parse($selector), true);
            $assert(! ($match['matches'] ?? false), $variant . ': no mobile display:none targeting the kept navigation: ' . $selector);
        }
    });
}

echo "Empty toggle mobile accordion navigation contract passed\n";
