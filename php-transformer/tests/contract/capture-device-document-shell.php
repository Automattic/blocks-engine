<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Every route is captured as three device documents: desktop, mobile, and a
// further profile (tablet) declared through the same capture grammar. The
// non-desktop documents carry a compact header/footer, so each area has
// distinct per-device variants that the shared part must keep.
$tablet = CaptureEvidence::ATTRIBUTE_PREFIX . 'tablet-document';
$documents = array(CaptureEvidence::DESKTOP_DOCUMENT_CLASS, CaptureEvidence::MOBILE_DOCUMENT_CLASS, $tablet);
$navigation = '<!-- wp:navigation {"overlayMenu":"never"} --><!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /--><!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom"} /--><!-- /wp:navigation -->';
$header = static fn(string $class): string => '<!-- wp:group {"className":"' . $class . '","tagName":"header"} --><header class="wp-block-group ' . $class . '"><!-- wp:paragraph --><p><a href="/">Four Fires</a></p><!-- /wp:paragraph -->' . $navigation . '</header><!-- /wp:group -->';
$footer = static fn(string $class): string => '<!-- wp:group {"className":"' . $class . '","tagName":"footer"} --><footer class="wp-block-group ' . $class . '"><!-- wp:paragraph --><p>Four Fires Ministries</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$main = static fn(string $title): string => '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --></main><!-- /wp:group -->';
$capture = static function (string $title) use ($documents, $header, $footer, $main): string {
    $markup = '';
    foreach ($documents as $position => $class) {
        $suffix = 0 === $position ? '' : ' compact';
        $markup .= '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '">' . $header('site-header' . $suffix) . $main($title) . $footer('site-footer' . $suffix) . '</div><!-- /wp:group -->';
    }
    return $markup;
};

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about/index.html' => '<main><h1>About</h1></main>',
    'contact/index.html' => '<main><h1>Contact</h1></main>',
)))->toArray();
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) $page['block_markup'] = $capture($page['source_path']);
unset($page);
$plan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($plan);

$codes = array_column($plan['diagnostics'], 'code');
$parts = array_column($plan['template_parts'], null, 'area');
$assert(isset($parts['header'], $parts['footer']), 'A third device document still yields shared header and footer parts: ' . json_encode($codes));
foreach (array('header', 'footer') as $area) {
    $part = (string) $parts[$area]['canonical_block_markup'];
    foreach ($documents as $class) $assert(str_contains($part, $class), "The shared {$area} part renders the {$class} variant.");
}
foreach ($plan['pages'] as $page) {
    $markup = (string) $page['canonical_block_markup'];
    $assert(!str_contains($markup, 'site-header') && !str_contains($markup, 'site-footer') && str_contains($markup, $page['source_path']), "{$page['source_path']} keeps its content and no header/footer copy.");
}
$assert(array() === array_filter($codes, static fn(string $code): bool => str_starts_with($code, 'wordpress_site_plan_shell_retained_')), 'No shell is retained page-owned: ' . json_encode($codes));
