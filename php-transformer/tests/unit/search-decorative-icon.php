<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Exercise the public compiler: source controls disappear during lowering.
$banded = 'banded' === ($argv[1] ?? '');
$rule = 'body.blog .search-input input{width:100%;height:auto;padding:0v w 1vw;padding-left:2.5vw;border:.15vw solid #000;font-size:1.5vw}';
$css = 'input{padding:0}.search-input form{position:relative}'
    . ($banded ? '@media(min-width:768px){' . $rule . '}' : $rule)
    . '.search-input .icon{display:block;position:absolute;top:0;left:0;width:1.5vw!important;height:1.5vw!important;transform:translate(.8vw,.8vw)}main{width:317px}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body class="blog"><main><div class="search-input"><form action="/search" method="get"><span class="icon"><svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="rgb(90,90,90)" d="M19.5 17.1 15.2 12.8C16 11.4 16.4 9.9 16.4 8.2 16.4 3.7 12.8 0 8.2 0 3.7 0 0 3.7 0 8.2s3.7 8.2 8.2 8.2c1.7 0 3.2-.5 4.5-1.4l4.4 4.4c.7.7 1.7.7 2.4 0 .6-.6.6-1.7 0-2.3ZM8.2 13.6c-3 0-5.4-2.4-5.4-5.4s2.4-5.4 5.4-5.4 5.4 2.4 5.4 5.4-2.4 5.4-5.4 5.4Z"/></svg></span><label for="q" style="display:none">Search</label><input id="q" name="q" type="text" placeholder="Search" aria-label="Search for blog posts"></form></div></main></body></html>';
$result = (new ArtifactCompiler())->compile(['files' => [['path' => 'index.html', 'kind' => 'html', 'content' => $html]]])->toArray();
$stylesheet = '';
foreach ($result['assets'] ?? [] as $asset) {
    if (str_ends_with($asset['path'] ?? '', '.css')) $stylesheet .= $asset['content'] . "\n";
}
if (!preg_match('/<!-- wp:search (.*?) \/-->/s', $result['serialized_blocks'], $match)) throw new RuntimeException('Expected native search');
$attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
if ('no-button' !== $attrs['buttonPosition'] || str_contains($result['serialized_blocks'], 'wp:html')) throw new RuntimeException('Decoration must not become a control or fallback');
if (!str_contains($stylesheet, 'data:image/svg+xml,') || !str_contains($stylesheet, 'pointer-events:none')) throw new RuntimeException('Lost decorative SVG');
if (preg_match('/wp-block-search__input\{[^}]*padding:[^;}]*!important/', $stylesheet)) throw new RuntimeException('Frozen base-band padding');
if (in_array('--json', $argv, true)) {
    echo json_encode(['src' => $html, 'html' => $result['serialized_blocks'], 'css' => $stylesheet], JSON_THROW_ON_ERROR);
} else {
    echo "Search decorative icon passed.\n";
}
