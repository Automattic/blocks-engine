<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $label) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($label);
    }
};

$dialogHtml = '<div role="dialog" aria-label="Site"><nav><a href="/">Home</a></nav></div>';
$dialog = static fn (): array => array(
    'html' => $dialogHtml,
    'htmlBytes' => strlen($dialogHtml),
    'htmlTruncated' => false,
);
$receipt = static fn (array $routes): array => array(
    'path' => 'capture-receipt.json',
    'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => $routes), JSON_UNESCAPED_SLASHES),
);
$report = static fn (array $pages): array => array(
    'path' => 'interaction-states.json',
    'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => $pages), JSON_UNESCAPED_SLASHES),
);
$state = static function (array $trigger, array $viewport = array()) use ($dialog): array {
    $page = array(
        'sourceUrl' => (string) ($trigger['sourceUrl'] ?? 'https://example.test/'),
        'states' => array(array(
            'status' => 'captured',
            'trigger' => $trigger['trigger'],
            'dialog' => $dialog(),
        )),
    );
    if (array() !== $viewport) {
        $page['viewport'] = $viewport;
    }
    return $page;
};
$project = static fn (array $files): array => ( new CapturedDialogProjector() )->project($files);
$codes = static fn (array $result): array => array_values(array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $result['diagnostics']));
$htmlByPath = static function (array $result, string $path): string {
    foreach ($result['files'] as $file) {
        if ($path === ($file['path'] ?? null)) {
            return (string) ($file['content'] ?? '');
        }
    }
    return '';
};

$binding = $project(array(
    array('path' => 'website/index.html', 'content' => '<main><a role="button" aria-haspopup="dialog" data-popupid="contact">Contact</a></main>'),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $report(array($state(array(
        'trigger' => array('selector' => 'body > main > a', 'tag' => 'a', 'ariaHaspopup' => 'dialog', 'dataBindings' => array('data-popupid' => 'contact')),
    )))),
));
$assert(1 === ($binding['projected_count'] ?? null), 'declarative popup binding still projects one dialog');
$assert(str_contains($htmlByPath($binding, 'website/index.html'), 'data-blocks-engine-captured-dialog="true"'), 'declarative match injects a captured dialog');

$homeHtml = '<header><button type="button" aria-label="Menu">Home menu</button></header>';
$aboutHtml = '<header><button type="button" aria-label="Menu">About menu</button></header>';
$routes = $project(array(
    array('path' => 'website/index.html', 'content' => $homeHtml),
    array('path' => 'website/about/index.html', 'content' => $aboutHtml),
    $receipt(array(
        array('url' => 'https://example.test/', 'path' => 'website/index.html'),
        array('url' => 'https://example.test/about', 'path' => 'website/about/index.html'),
    )),
    $report(array(
        $state(array(
            'sourceUrl' => 'https://example.test/',
            'trigger' => array('selector' => 'body > div > header > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()),
        ), array('width' => 390, 'height' => 844)),
        $state(array(
            'sourceUrl' => 'https://example.test/about',
            'trigger' => array('selector' => 'body > div > header > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()),
        ), array('width' => 390, 'height' => 844)),
    )),
));
$homeProjected = $htmlByPath($routes, 'website/index.html');
$aboutProjected = $htmlByPath($routes, 'website/about/index.html');
$assert(2 === ($routes['projected_count'] ?? null), 'each route projects its own captured dialog');
$assert(array() === $codes($routes), 'route-scoped matches emit no trigger diagnostics');
$assert(str_contains($homeProjected, 'blocks-engine-dialog-trigger-') && str_contains($homeProjected, 'Home menu'), 'home route binds the home menu control');
$assert(str_contains($aboutProjected, 'blocks-engine-dialog-trigger-') && str_contains($aboutProjected, 'About menu'), 'about route binds the about menu control');
preg_match('/id="(blocks-engine-dialog-trigger-[a-f0-9-]+)"/', $homeProjected, $homeTrigger);
preg_match('/id="(blocks-engine-dialog-trigger-[a-f0-9-]+)"/', $aboutProjected, $aboutTrigger);
$assert(($homeTrigger[1] ?? '') !== ($aboutTrigger[1] ?? '') && '' !== ($homeTrigger[1] ?? ''), 'route-scoped bindings keep distinct trigger identities');
$assert(! str_contains($homeProjected, 'About menu') && ! str_contains($aboutProjected, 'Home menu'), 'one route cannot bind another route trigger');

$responsiveHtml = '<body>'
    . '<div class="data-liberation-desktop-document"><button type="button" aria-label="Menu">Desktop</button></div>'
    . '<div class="data-liberation-mobile-document"><details><summary aria-label="Menu">Mobile</summary></details></div>'
    . '</body>';
$mobileCapture = $project(array(
    array('path' => 'website/index.html', 'content' => $responsiveHtml),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $report(array($state(array(
        'trigger' => array('selector' => 'body > div > div > header > nav > div > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()),
    ), array('width' => 390, 'height' => 844)))),
));
$mobileHtml = $htmlByPath($mobileCapture, 'website/index.html');
$assert(1 === ($mobileCapture['projected_count'] ?? null), 'mobile viewport projects one dialog after wrapper normalization');
$assert(array() === $codes($mobileCapture), 'structural identity change does not emit unmatched diagnostics');
$assert(1 === preg_match('/<summary[^>]*id="blocks-engine-dialog-trigger-[a-f0-9-]+"[^>]*>Mobile<\/summary>/', $mobileHtml), 'mobile capture binds the rewritten summary in the mobile document');
$assert(! preg_match('/<button[^>]*id="blocks-engine-dialog-trigger-/', $mobileHtml), 'mobile capture does not bind the desktop control');

$variantHtml = '<body>'
    . '<div class="site-document-variant-default"><button type="button" aria-label="Menu">Default</button></div>'
    . '<div class="site-document-variant-mobile"><button type="button" aria-label="Menu">Variant</button></div>'
    . '</body>';
$variantCapture = $project(array(
    array('path' => 'website/index.html', 'content' => $variantHtml),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $report(array($state(array(
        'trigger' => array('selector' => 'body > header > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()),
    ), array('width' => 390, 'height' => 844)))),
));
$variantProjected = $htmlByPath($variantCapture, 'website/index.html');
$assert(1 === ($variantCapture['projected_count'] ?? null), 'composed document variants project one dialog');
$assert(1 === preg_match('/<button[^>]*id="blocks-engine-dialog-trigger-[a-f0-9-]+"[^>]*>Variant<\/button>/', $variantProjected), 'narrow viewport binds the mobile document variant');
$assert(! preg_match('/<button[^>]*id="blocks-engine-dialog-trigger-[^"]+"[^>]*>Default<\/button>/', $variantProjected), 'narrow viewport does not bind the default document variant');

$ambiguous = $project(array(
    array('path' => 'website/index.html', 'content' => '<main><button type="button" aria-label="Menu">One</button><button type="button" aria-label="Menu">Two</button></main>'),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $report(array($state(array(
        'trigger' => array('selector' => 'body > main > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()),
    ), array('width' => 390, 'height' => 844)))),
));
$assert(0 === ($ambiguous['projected_count'] ?? null), 'ambiguous matches fail closed');
$assert(array('captured_dialog_trigger_ambiguous') === $codes($ambiguous), 'ambiguous matches emit a fail-closed diagnostic');
$assert(! str_contains($htmlByPath($ambiguous, 'website/index.html'), 'data-blocks-engine-captured-dialog'), 'ambiguous matches do not inject a dialog');

$missing = $project(array(
    array('path' => 'website/index.html', 'content' => '<main><button type="button" aria-label="Search">Search</button></main>'),
    $receipt(array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))),
    $report(array($state(array(
        'trigger' => array('selector' => 'body > main > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()),
    ), array('width' => 390, 'height' => 844)))),
));
$assert(0 === ($missing['projected_count'] ?? null), 'missing matches fail closed');
$assert(array('captured_dialog_trigger_unmatched') === $codes($missing), 'missing matches emit unmatched diagnostics');

echo "OK: captured dialog projector passed ({$assertions} assertions)\n";
