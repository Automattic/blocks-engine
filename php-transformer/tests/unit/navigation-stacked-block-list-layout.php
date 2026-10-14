<?php
declare(strict_types=1);

/**
 * A list navigation whose items the source stacks as blocks keeps that flow.
 *
 * An in-flow list navigation renders its generated list as
 * `display:contents`, so the items lay out in core/navigation itself. With no
 * layout that is core's flex row: a Wix phone menu (`.menu>ul li{display:block}`
 * with no display on the nav or the list) came out as flex items sized by their
 * `min-width`, and the longer labels were cut off. Only item evidence switches
 * the default: an unstyled menu, inline items, and a flex list keep
 * core's row.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$items = '';
foreach ( array( 'Contact Us' => '/contact', 'Annual Meetings' => '/meetings', 'News &amp; Events' => '/events' ) as $label => $href ) {
    $items .= '<li class="item"><div class="box"><span class="wrap"><a class="link" href="' . $href . '">' . $label . '</a></span></div></li>';
}
$layout = static function (string $css, string $scope = '') use ($items): ?array {
    $body = '<nav class="menu"><ul class="list">' . $items . '</ul></nav>';
    if ( '' !== $scope ) {
        $body = '<div class="data-liberation-mobile-document" data-dla-device-document="mobile" data-dla-document-scope="">'
            . '<header>' . $body . '</header><main><p>Body</p></main></div>';
    }
    $serialized = (string) (( new HtmlTransformer() )->transform('<style>' . $css . '</style>' . $body, array())->toArray()['serialized_blocks'] ?? '');
    if ( 1 !== preg_match('/<!-- wp:navigation (\{.*?\}) (?:\/)?-->/', $serialized, $match) ) {
        return array( 'missing' => true );
    }
    $attrs = json_decode($match[1], true);

    return is_array($attrs) && is_array($attrs['layout'] ?? null) ? $attrs['layout'] : null;
};
$menu = static fn (string $scope, string $itemRule): string => $scope . '.menu{direction:ltr}'
    . $scope . '.menu>ul{box-sizing:border-box;width:100%}'
    . $scope . '.menu>ul li{' . $itemRule . '}'
    // Later in source order but weaker: the typed item rule still wins.
    . $scope . '.item{display:flex;min-width:100px}'
    . $scope . '.item .box{display:grid;height:45px}';

$scope = ':where([data-dla-device-document="mobile"]) ';
$assert(
    array( 'type' => 'default' ) === $layout($menu($scope, 'display:block'), $scope),
    'a device-scoped menu whose items are declared block stacks in core flow',
    json_encode($layout($menu($scope, 'display:block'), $scope))
);
$assert(
    array( 'type' => 'default' ) === $layout($menu('', 'display:block')),
    'the same menu without a device scope stacks in core flow'
);
$assert(
    array( 'type' => 'default' ) === $layout($menu('', 'display:list-item')),
    'list-item items are block-level too'
);
$assert(null === $layout($menu('', 'display:inline-block')), 'inline-block items keep core\'s row');
$assert(
    array( 'type' => 'default' ) === $layout($menu('', 'display:block;float:left')),
    'floated block items take flow, where their projected float still applies'
);
$assert(null === $layout($menu('', 'display:block') . '.menu>ul{display:flex}'), 'a flex list keeps core\'s row');
$assert(null === $layout('.menu{direction:ltr}'), 'a menu without item display evidence keeps core\'s default');
$assert(null === $layout('.item{display:flex}'), 'flex items keep core\'s row');

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation stacked block list layout: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation stacked block list layout passed: {$passes} assertions\n";
