<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

// #7244: the padding spacer, not the moved link's line box, centers the logo.
$source = '<style>body{margin:0}.top{height:48px}'
    . '.main-header{display:flex;align-items:center;height:110px}'
    . '.header-logo{height:110px;width:368px;padding-left:100px}'
    . '.header-logo__link{height:inherit;display:inline-block;position:relative;width:100%;text-decoration:none}'
    . '.header-logo-image-container{position:relative}'
    . '.header-logo-image-container:after{content:"";display:block;padding-bottom:100px}'
    . '.header-logo-image{position:absolute;top:0;bottom:0;left:0;right:0;margin:auto;max-height:75px;max-width:250px}'
    . '.header-logo--left .header-logo-image{right:unset}</style>'
    . '<div class="top">Banner</div><div class="main-header"><div class="header-logo header-logo--left">'
    . '<a class="header-logo__link" href="/"><div class="header-logo-image-container">'
    . '<img class="header-logo-image" src="/wide.png" width="2400" height="2000" alt="Store logo">'
    . '</div></a></div><p>Search</p></div>';
$result = (new HtmlTransformer())->transform($source, array())->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$link = (new DOMXPath($document))->query('//figure[contains(@class,"header-logo-image-container")]/a[@href="/"]')->item(0);
if ( ! $link instanceof DOMElement || ! str_contains($result['serialized_blocks'], '<!-- wp:image ') ) {
    throw new RuntimeException('Expected the container to become a native image figure with the link inside.');
}
$matcher = new CssSelectorMatcher();
$transparent = false;
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
foreach ( $rules as $rule ) {
    if ( str_contains($rule[2], 'display:block!important')
        && str_contains($rule[2], 'position:static!important')
        && str_contains($rule[2], 'height:auto!important')
        && str_contains($rule[2], 'width:auto!important')
        && $matcher->matches($link, $matcher->parse(trim($rule[1])))['matches'] ) {
        $transparent = true;
    }
}
if ( ! $transparent ) {
    throw new RuntimeException('The moved link must not form a positioned, sized box inside the image container.');
}

echo "Linked image swapped containing block passed\n";
