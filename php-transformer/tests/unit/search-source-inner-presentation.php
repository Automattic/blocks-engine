<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// core/search cannot store the source input's class, so rules keyed on it are
// re-targeted onto .wp-block-search__input; a background-painted empty sibling
// becomes the search button, painted through ::before.
$css = '.finder .finder-input{background:transparent;color:#fff;max-width:105px}'
    . '.finder-input:focus{color:red}'
    . '@media(max-width:600px){.finder-input{max-width:80px}}'
    . '.finder-button-inner{display:block;width:16px;height:16px;background:url("/search.png") center/contain no-repeat}';
$rulesFor = static function (string $stylesheet, string $part): array {
    $rules = array();
    (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $selector, string $body) use ($part, &$rules): void {
        if (str_contains($selector, $part)) $rules[] = $selector . '{' . $body . '}';
    });
    return $rules;
};
foreach (array(
    'input only' => array('', 'no-button'),
    'background icon' => array('<span class="finder-button"><span class="finder-button-inner"></span></span>', 'button-outside'),
) as $case => [$icon, $position]) {
    $html = '<table role="presentation"><tr><td class="finder"><span class="site-search"><form method="get"><input type="text" name="q" class="finder-input" placeholder="Search">' . $icon . '</form></span></td><td>Header</td></tr></table>';
    $result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
    $stylesheet = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $input = implode("\n", $rulesFor($stylesheet, 'wp-block-search__input'));
    $button = implode("\n", $rulesFor($stylesheet, 'wp-block-search__button'));
    $checks = array(
        'resting input rule' => 1 === preg_match('/wp-block-search__input[^{]*\{[^}]*color:#fff[^}]*max-width:105px/', $input),
        'state rule' => 1 === preg_match('/wp-block-search__input[^{]*:focus[^{]*\{[^}]*color:red/', $input),
        'media rule' => str_contains($input, 'max-width:80px'),
        'button position' => str_contains($result['serialized_blocks'], '"buttonPosition":"' . $position . '"'),
        'icon paint' => '' === $icon
            ? '' === $button
            : 1 === preg_match('/wp-block-search__button[^{]*::before\{[^}]*url\(/', $button) && str_contains($button, '>svg{display:none}'),
    );
    foreach ($checks as $name => $ok) if (!$ok) throw new RuntimeException("$case: $name\n$stylesheet");
}
echo "Search source inner presentation passed.\n";
