<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$fixture = require dirname(__DIR__) . '/fixtures/device-document-shared-shell.php';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$parts = array_column($plan['template_parts'], null, 'area');
$assert(isset($parts['header'], $parts['footer']), 'Both device document shell areas must be extracted.');
foreach ($plan['templates'] as $template) {
    foreach (array('header', 'footer') as $area) $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $area . '"'), $template['slug'] . ' references ' . $area);
}
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], 'Farm Sanctuary') && !str_contains($page['canonical_block_markup'], 'Footer text'), 'Page body must not retain shell landmarks.');
    $assert(str_contains($page['canonical_block_markup'], 'page body.'), 'Route content remains page-owned.');
}
foreach ($parts as $part) {
    foreach (array('desktop', 'mobile') as $device) $assert(str_contains($part['canonical_block_markup'], 'data-blocks-engine-capture-' . $device . '-document'), 'Shell parts retain both device scopes.');
}
$assert(!array_filter($plan['reporting']['scorecard']['shared_shell']['measured'] ?? array()), 'shared_shell scorecard passes.');
// Removing the mobile copy and desktop carrier gives the paired plain capture.
foreach ($fixture['files'] as &$html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*[@data-blocks-engine-capture-document-scope]') as $wrapper) {
        if (str_contains($wrapper->getAttribute('class'), 'capture-mobile-document')) {
            $wrapper->parentNode->removeChild($wrapper);
            continue;
        }
        while ($wrapper->firstChild) $wrapper->parentNode->insertBefore($wrapper->firstChild, $wrapper);
        $wrapper->parentNode->removeChild($wrapper);
    }
    $html = $dom->saveHTML();
}
unset($html);
$plain = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plain['templates'] as $template) {
    foreach (array('header', 'footer') as $area) $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $area . '"'), 'Plain capture still references ' . $area);
}
foreach ($plain['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], 'Farm Sanctuary') && !str_contains($page['canonical_block_markup'], 'Footer text'), 'Plain capture still removes shell content.');
$assert(!array_filter($plain['reporting']['scorecard']['shared_shell']['measured'] ?? array()), 'Plain shared_shell still passes.');
fwrite(STDOUT, "device-document-shared-shell contract passed\n");
