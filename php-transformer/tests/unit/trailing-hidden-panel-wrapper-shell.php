<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Reduced from a Google Sites capture (#4543): one body wrapper holds the
// banner, a per-route header and the page section, and the captured "Site
// actions" menu mounts after it as a hidden panel. The wrapper is the page,
// not header chrome, so every route must keep its own section.
$banner = '<div role="banner"><div class="BuY5Fd"></div><div class="TbNlJb"><div class="E2UJ5"><div class="rFrNMe b7AJhc zKHdkd"><div class="aCsJod"><div><div><div aria-hidden="true">Search this site</div></div><div></div><div></div></div></div><div><div></div></div></div></div></div></div>';
$header = static fn(string $current, string $item, string $class): string => '<header id="atIdViewHeader"><div class="' . $class . '"><div tabindex="0" aria-hidden="true" class="pw1uU"></div><nav role="navigation" id="yuynLe"><a href="/index.html"><span>Kitten Site</span></a><ul><li><div class="PsKE7e IKA38e" aria-current="true"><div class="I35ICb"><a role="link" href="' . $current . '">' . $item . '</a></div></div></li></ul></nav><div class="TxnWlb XPSexb"></div></div></header>';
$panel = '<div role="menu" hidden="" id="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0"><div><div><span role="menuitem"><div></div><div><div>Report abuse</div></div></span><span role="menuitem"><div></div><div><div>Page details</div></div></span></div></div></div>';
$trigger = '<div role="button" aria-label="Site actions" aria-haspopup="true" aria-expanded="false" id="ow11" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0" aria-controls="data-blocks-engine-capture-dialog-0"><div></div><span><span></span></span></div>';
$page = static fn(string $header, string $body): string => '<html><body><div><div><div><div></div>' . $banner . '</div><div><div><div><div></div><div></div></div></div><div></div><div><div>' . $header
    . '<div><section><h1>' . $body . '</h1><p>Unique text for ' . $body . '</p></section></div><div></div><div>' . $trigger . '</div></div></div></div></div></div><div></div>' . $panel . '</body></html>';

$routes = array(
    'index.html' => $page($header('/index.html', 'Home', 'BbxBP G8QRnc'), 'Home'),
    'about/index.html' => $page($header('/about/index.html', 'About', 'BbxBP'), 'About'),
    'faq/index.html' => $page($header('/faq/index.html', 'FAQ', 'BbxBP'), 'FAQ'),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $routes))->toArray();
$plan = (new WordPressSitePlan())->fromResult($result);

$titles = array('index.html' => 'Home', 'about/index.html' => 'About', 'faq/index.html' => 'FAQ');
foreach ($plan['pages'] as $planned) {
    $title = $titles[$planned['source_path']];
    $assert(str_contains($planned['canonical_block_markup'], 'Unique text for ' . $title), $planned['source_path'] . ' must keep its own section content');
}
foreach ($plan['template_parts'] as $part) {
    $assert(!str_contains($part['canonical_block_markup'], 'Unique text for'), 'No template part may absorb route content');
}
fwrite(STDOUT, "Trailing hidden panel wrapper shell tests passed\n");
