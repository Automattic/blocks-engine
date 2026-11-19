<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A source submit with no authored background or border paints the UA's light
// button face. Converted to core/search it must not fall back to core's dark
// `.wp-element-button` default while the author `*{color:#444}` sets its text.
$buttonRules = static function (string $extraCss): array {
    $html = '<!doctype html><html><head><style>*{color:#444;font-family:Helvetica,sans-serif}' . $extraCss . '</style></head><body>'
        . '<header><form action="/" method="get" role="search"><input type="search" name="s" aria-label="Search"><input type="submit" value="Search"></form></header>'
        . '</body></html>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    if (! str_contains($result['serialized_blocks'], '<!-- wp:search ') || ! str_contains($result['serialized_blocks'], '"buttonPosition":"button-outside"')) {
        throw new RuntimeException("Fixture did not lower to core/search with a button:\n" . $result['serialized_blocks']);
    }
    $stylesheet = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $rules = array();
    (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $selector, string $body) use (&$rules): void {
        if (str_contains($selector, 'wp-block-search__button') && ! str_contains($selector, ':hover') && ! str_contains($selector, ':focus')) {
            $rules[] = $selector . '{' . $body . '}';
        }
    });

    return array(implode("\n", $rules), $stylesheet);
};

$failures = array();

[$rules, $stylesheet] = $buttonRules('');
if (1 !== preg_match('/wp-block-search__button\{[^}]*background-color:#efefef/i', $rules)) {
    $failures[] = "UA-default button does not resolve to the source's light UA background:\n" . $rules;
}
if (1 !== preg_match('/wp-block-search__button\{[^}]*border:2px outset/i', $rules)) {
    $failures[] = "UA-default button does not carry the source's UA border:\n" . $rules;
}
if (str_contains(strtolower($stylesheet), '#32373c')) {
    $failures[] = "Generated styles paint the button with core's dark default.";
}
if (1 !== preg_match('/\*\{[^}]*color:#444/', $stylesheet) || 1 === preg_match('/wp-block-search__button\{[^}]*(?<![-a-z])color:#000/i', $rules)) {
    $failures[] = "The authored text color must stay the author's, not a UA fallback:\n" . $rules;
}

[$rules] = $buttonRules('input[type=submit]{background:#c00;border:0}');
if (1 !== preg_match('/wp-block-search__button[^{]*\{[^}]*background:#c00/i', $rules)) {
    $failures[] = "Authored button background is not projected onto the search button:\n" . $rules;
}
if (1 === preg_match('/#efefef|outset/i', $rules)) {
    $failures[] = "UA button paint overrides an authored button background:\n" . $rules;
}

[$rules] = $buttonRules('header{color:#fff}');
if (1 !== preg_match('/wp-block-search__button\{[^}]*background-color:#efefef/i', $rules)) {
    $failures[] = "UA-default button background missing when only ancestors set color:\n" . $rules;
}

if (array() !== $failures) {
    fwrite(STDERR, implode("\n\n", $failures) . "\n");
    exit(1);
}
echo "UA-default button not core dark passed.\n";
