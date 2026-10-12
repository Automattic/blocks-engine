<?php
declare(strict_types=1);

/**
 * A typed item rule under a captured document scope keeps its source rank.
 *
 * Responsive captures scope every author rule to its device document
 * (`:where([data-dla-device-document="mobile"]) .menu>ul li`). That data
 * ancestry sends the rule to the attribute-ancestry projection, which binds
 * the subject to the item's element identity at bare source weight. Untyped
 * rules on the same item (`.item{display:flex}`) take the shared native
 * navigation subject baseline instead, so they outranked the typed rule that
 * beat them in the source, and so did Core's `.wp-block-navigation-item`
 * defaults. On a Wix phone menu the items stayed flex and every absolutely
 * positioned link collapsed to 0px wide.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$scope = ':where([data-dla-device-document="mobile"]) ';
$items = '';
foreach ( array( 'Home' => '/', 'About' => '/about', 'Contact' => '/contact' ) as $label => $href ) {
    $items .= '<li class="item"><div class="box"><span class="label"><a class="link" href="' . $href . '">' . $label . '</a></span></div></li>';
}
$css = $scope . '.item{display:flex;min-width:100px;position:relative}'
    . $scope . '.menu>ul li{display:block}'
    . $scope . '.item .box{display:grid;height:50px;position:relative}'
    . $scope . '.item .box>.label>.link{position:absolute;inset:0}';
$compile = static function (string $css) use ($items): array {
    $source = '<style>' . $css . '</style>'
        . '<div class="data-liberation-mobile-document" data-dla-device-document="mobile" data-dla-document-scope="">'
        . '<header><nav class="menu"><ul>' . $items . '</ul></nav></header><main><p>Body</p></main></div>';
    $result = ( new HtmlTransformer() )->transform($source, array())->toArray();
    $candidateCss = implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));
    $displays = array();
    foreach ( array( 'source' => $source, 'native' => StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks']) ) as $kind => $html ) {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $cascade = new StaticCssCascade($dom, 'native' === $kind ? $candidateCss : '');
        foreach ( $dom->getElementsByTagName('li') as $item ) {
            $displays[$kind][trim($item->textContent ?? '')] = $cascade->resolve($item, array( 'display' ), array())['display'] ?? '';
        }
    }
    return array( $candidateCss, $displays );
};

[ $candidateCss, $displays ] = $compile($css);

$assert(
    array( 'Home' => 'block', 'About' => 'block', 'Contact' => 'block' ) === ($displays['source'] ?? null),
    'the typed rule wins in the source',
    json_encode($displays)
);
$assert(
    ($displays['source'] ?? null) === ($displays['native'] ?? null),
    'the typed rule still outranks the untyped item rule on the rendered items',
    json_encode($displays)
);

// Core styles `.wp-block-navigation .wp-block-navigation-item{display:flex}`.
// The typed rule must carry the same native baseline as every other projected
// item rule, and keep its device document scope.
$typedSelectors = array();
foreach ( preg_split('/}\s*/', $candidateCss) ?: array() as $rule ) {
    if ( str_ends_with(trim($rule), '{display:block') ) {
        array_push($typedSelectors, ...array_map('trim', explode(',', substr(trim($rule), 0, -strlen('{display:block')))));
    }
}
$native = array_filter($typedSelectors, static fn (string $selector): bool => 1 === preg_match('/^:root :where\(\[data-dla-device-document="mobile"\]\) \.wp-block-navigation \.wp-block-navigation-item\.blocks-engine-navigation-item-[a-f0-9]{12}-\d+:not\(/', $selector));
$assert(
    3 === count($native),
    'each rendered item takes the scoped native subject baseline',
    json_encode($typedSelectors)
);

// The scope must stay on the projected rule. A typed rule scoped to another
// device document (here desktop) never reaches the mobile items, so they keep
// the mobile rule's `block` rather than taking the desktop `table`.
[ $leakCss, $leakDisplays ] = $compile(':where([data-dla-device-document="desktop"]) .menu>ul li{display:table}' . $css);
$assert(
    array( 'Home' => 'block', 'About' => 'block', 'Contact' => 'block' ) === ($leakDisplays['native'] ?? null),
    'a typed item rule scoped to another device document does not reach the mobile items',
    json_encode($leakDisplays)
);
$assert(
    1 !== preg_match('/:root (?!:where\(\[data-dla-device-document="desktop"\]\) )[^{}]*\.wp-block-navigation-item\.blocks-engine-navigation-item-[^{}]*\{display:table/', $leakCss),
    'no unscoped native item selector carries the desktop declaration',
    ''
);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation scoped typed item precedence FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "navigation scoped typed item precedence passed: {$passes} assertions\n";
