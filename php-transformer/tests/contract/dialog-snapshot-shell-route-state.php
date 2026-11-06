<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A captured mobile dialog is folded into a layout-shell wrapper whose
// existing-panel snapshot (stored in the block comment and the saved
// attribute) records which menu item is current on this route. That route
// state must not make every route's mobile header unique, and the shared
// part must not freeze one route's selection.
$tablet = CaptureEvidence::ATTRIBUTE_PREFIX . 'tablet-document';
$documents = array(CaptureEvidence::DESKTOP_DOCUMENT_CLASS, CaptureEvidence::MOBILE_DOCUMENT_CLASS, $tablet);
$routes = array('index.html' => '/', 'about/index.html' => '/about/', 'contact/index.html' => '/contact/');
$panel = static function (string $current) use ($routes): string {
    $items = '';
    foreach ($routes as $url) $items .= $url === $current ? '<li aria-current="page" class="item is-current"><a href="' . $url . '">' . $url . '</a></li>' : '<li class="item"><a href="' . $url . '">' . $url . '</a></li>';
    $html = '<div id="MENU" role="dialog"><ul>' . $items . '</ul></div>';
    $snapshot = json_encode(array('resting' => array('html' => $html, 'style' => null, 'class' => 'data-blocks-engine-capture-dialog', 'hidden' => true), 'opened' => array('html' => $html, 'style' => null, 'class' => 'data-blocks-engine-capture-dialog', 'hidden' => false)), JSON_UNESCAPED_SLASHES);
    $attributes = array('class' => 'data-blocks-engine-capture-dialog', 'data-blocks-engine-capture-existing-panel' => $snapshot);
    return '<!-- wp:ssi-site/layout-shell ' . json_encode(array('wrappers' => array(array('tagName' => 'div', 'attributes' => $attributes))), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) . ' --><div class="data-blocks-engine-capture-dialog" data-blocks-engine-capture-existing-panel="' . htmlspecialchars($snapshot, ENT_QUOTES) . '"><!-- wp:paragraph --><p>Menu</p><!-- /wp:paragraph --></div><!-- /wp:ssi-site/layout-shell -->';
};
$header = static fn(string $class, string $dialog): string => '<!-- wp:group {"className":"' . $class . '","tagName":"header"} --><header class="wp-block-group ' . $class . '"><!-- wp:paragraph --><p><a href="/">Four Fires</a></p><!-- /wp:paragraph -->' . $dialog . '</header><!-- /wp:group -->';
$main = static fn(string $title): string => '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->';
$capture = static function (string $path) use ($documents, $routes, $header, $main, $panel): string {
    $markup = '';
    foreach ($documents as $position => $class) {
        $dialog = 1 === $position ? $panel($routes[$path]) : '';
        $markup .= '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '">' . $header('site-header' . (0 === $position ? '' : ' compact'), $dialog) . $main($path) . '</div><!-- /wp:group -->';
    }
    return $markup;
};

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array_map(static fn(string $url): string => '<main><h1>' . $url . '</h1></main>', $routes)))->toArray();
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) $page['block_markup'] = $capture($page['source_path']);
unset($page);
$plan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($plan);

$parts = array_column($plan['template_parts'], null, 'area');
$assert(isset($parts['header']), 'Route-current state inside a dialog snapshot still yields a shared header: ' . json_encode(array_column($plan['diagnostics'], 'code')));
$part = (string) $parts['header']['canonical_block_markup'];
$assert(str_contains($part, 'data-blocks-engine-capture-existing-panel'), 'The shared header keeps the dialog snapshot.');
$assert(!str_contains($part, 'aria-current') && !str_contains($part, 'is-current') && !str_contains($part, 'aria-current\u0022') , 'The shared header snapshot carries no route-current state.');
foreach ($plan['pages'] as $page) $assert(!str_contains((string) $page['canonical_block_markup'], 'site-header'), "{$page['source_path']} renders no page-owned header copy.");
