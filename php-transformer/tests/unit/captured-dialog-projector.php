<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$dialogHtml = '<nav aria-label="Site"><ul><li><a href="/home">Home</a></li></ul></nav>';
$dialog = array('html' => $dialogHtml, 'htmlBytes' => strlen($dialogHtml), 'htmlTruncated' => false);
$menuTrigger = array(
    'selector' => 'body > div > header > nav > div > button',
    'tag' => 'button',
    'ariaHaspopup' => '',
    'label' => 'Menu',
    'dataBindings' => array(),
);

$project = static function (array $files) {
    return (new CapturedDialogProjector())->project($files);
};

$codes = static function (array $result): array {
    return array_values(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $result['diagnostics']));
};

$triggerIds = static function (string $html): array {
    if (1 !== preg_match('/data-blocks-engine-triggers="([^"]+)"/', $html, $match)) {
        return array();
    }
    return preg_split('/\s+/', trim($match[1])) ?: array();
};

$receipt = static function (array $routes): array {
    return array('path' => 'capture-receipt.json', 'content' => json_encode(array(
        'schema' => 'data-liberation/capture-receipt/v1',
        'routes' => $routes,
    ), JSON_UNESCAPED_SLASHES));
};

$states = static function (array $pages): array {
    return array('path' => 'interaction-states.json', 'content' => json_encode(array(
        'schema' => 'data-liberation/captured-interactions/v1',
        'pages' => $pages,
    ), JSON_UNESCAPED_SLASHES));
};

$page = static function (string $url, array $trigger) use ($dialog): array {
    return array(
        'sourceUrl' => $url,
        'viewport' => array('width' => 390, 'height' => 844),
        'states' => array(array('status' => 'captured', 'trigger' => $trigger, 'dialog' => $dialog)),
    );
};

$responsiveHtml = '<html><body>'
    . '<div class="data-liberation-desktop-document"><header><nav><details><summary id="desktop-menu" aria-label="Menu">Desktop</summary></details></nav></header></div>'
    . '<div class="data-liberation-mobile-document"><header><nav><details><summary id="mobile-menu" aria-label="Menu">Mobile</summary></details></nav></header></div>'
    . '</body></html>';

$matched = $project(array(
    array('path' => 'website/index.html', 'content' => $responsiveHtml),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $states(array($page('https://example.test/', $menuTrigger))),
));
$again = $project(array(
    array('path' => 'website/index.html', 'content' => $responsiveHtml),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $states(array($page('https://example.test/', $menuTrigger))),
));
$assert(1 === ($matched['projected_count'] ?? null), 'responsive copies project one dialog');
$assert(array() === $codes($matched), 'responsive copies emit no trigger diagnostics', implode(',', $codes($matched)));
$assert(array('desktop-menu', 'mobile-menu') === $triggerIds((string) $matched['files'][0]['content']), 'each responsive document contributes one rewritten trigger', implode(' ', $triggerIds((string) $matched['files'][0]['content'])));
$assert($matched === $again, 'responsive trigger matching is deterministic');

$variantHtml = '<html><body>'
    . '<div class="site-document-variant-default"><header><nav><button id="default-menu" aria-label="Menu">Desktop</button></nav></header></div>'
    . '<div class="site-document-variant-mobile"><header><nav><button id="variant-menu" aria-label="Menu">Mobile</button></nav></header></div>'
    . '</body></html>';
$variant = $project(array(
    array('path' => 'website/index.html', 'content' => $variantHtml),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $states(array($page('https://example.test/', $menuTrigger))),
));
$assert(1 === ($variant['projected_count'] ?? null) && array('default-menu', 'variant-menu') === $triggerIds((string) $variant['files'][0]['content']), 'compiler variant wrappers each bind one trigger');

$ambiguous = $project(array(
    array('path' => 'website/index.html', 'content' => '<html><body><div class="data-liberation-mobile-document"><header><nav><button aria-label="Menu">One</button><button aria-label="Menu">Two</button></nav></header></div></body></html>'),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $states(array($page('https://example.test/', $menuTrigger))),
));
$assert(0 === ($ambiguous['projected_count'] ?? null), 'two matching controls in one responsive document do not project');
$assert(array('captured_dialog_trigger_ambiguous') === $codes($ambiguous), 'ambiguous matches fail closed', implode(',', $codes($ambiguous)));

$missing = $project(array(
    array('path' => 'website/index.html', 'content' => '<html><body><div class="data-liberation-mobile-document"><header><nav><button aria-label="Search">Search</button></nav></header></div></body></html>'),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $states(array($page('https://example.test/', $menuTrigger))),
));
$assert(0 === ($missing['projected_count'] ?? null) && array('captured_dialog_trigger_unmatched') === $codes($missing), 'genuinely missing triggers stay unmatched');

$bindingHtml = '<header><nav><a id="contact" role="button" aria-haspopup="dialog" data-popupid="contact">Contact</a></nav></header>';
$bound = $project(array(
    array('path' => 'website/index.html', 'content' => $bindingHtml),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $states(array(array(
        'sourceUrl' => 'https://example.test/',
        'states' => array(array(
            'status' => 'captured',
            'trigger' => array('selector' => 'body > header > nav > a', 'tag' => 'a', 'ariaHaspopup' => 'dialog', 'dataBindings' => array('data-popupid' => 'contact')),
            'dialog' => $dialog,
        )),
    ))),
));
$assert(1 === ($bound['projected_count'] ?? null) && array('contact') === $triggerIds((string) $bound['files'][0]['content']), 'declarative bindings still match exactly one trigger');

$conflict = $project(array(
    array('path' => 'website/index.html', 'content' => '<header><nav><a id="contact" role="button" aria-haspopup="dialog" data-popupid="other">Contact</a></nav></header>'),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $states(array(array(
        'sourceUrl' => 'https://example.test/',
        'states' => array(array(
            'status' => 'captured',
            'trigger' => array('selector' => '#contact', 'tag' => 'a', 'ariaHaspopup' => 'dialog', 'label' => 'Contact', 'dataBindings' => array('data-popupid' => 'contact')),
            'dialog' => $dialog,
        )),
    ))),
));
$assert(0 === ($conflict['projected_count'] ?? null) && array('captured_dialog_trigger_unmatched') === $codes($conflict), 'conflicting declarative bindings fail closed');

$homeHtml = '<html><body><div class="data-liberation-mobile-document"><header><nav><summary id="home-menu" aria-label="Menu">Home</summary></nav></header></div></body></html>';
$aboutHtml = '<html><body><div class="data-liberation-mobile-document"><header><nav><summary id="about-menu" aria-label="Menu">About</summary></nav></header></div></body></html>';
$routed = $project(array(
    array('path' => 'website/index.html', 'content' => $homeHtml),
    array('path' => 'website/about/index.html', 'content' => $aboutHtml),
    $receipt(array(
        array('url' => 'https://example.test/', 'path' => 'website/index.html'),
        array('url' => 'https://example.test/about', 'path' => 'website/about/index.html'),
    )),
    $states(array(
        $page('https://example.test/', $menuTrigger),
        $page('https://example.test/about', $menuTrigger),
    )),
));
$assert(2 === ($routed['projected_count'] ?? null), 'each route binds its own captured menu');
$assert(array('home-menu') === $triggerIds((string) $routed['files'][0]['content']), 'home route does not bind the about trigger');
$assert(array('about-menu') === $triggerIds((string) $routed['files'][1]['content']), 'about route does not bind the home trigger');

if ($failures > 0) {
    fwrite(STDERR, "captured-dialog-projector failed ({$failures} failures, {$passes} passes)\n");
    exit(1);
}
echo "OK: captured-dialog-projector passed ({$passes} assertions)\n";
