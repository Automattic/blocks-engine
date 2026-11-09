<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$document = static function (string $title, bool $identical, bool $conflict): string {
    $html = '<style>@media (max-width:767px){.data-blocks-engine-capture-desktop-document{display:none}}@media (min-width:768px){.data-blocks-engine-capture-mobile-document{display:none}}</style>';
    foreach (array('desktop', 'mobile') as $device) {
        $menu = 'mobile' === $device && !$identical ? '<nav class="burger"><a href="/">Menu</a></nav>' : '<nav class="menu"><a href="/">Home</a><a href="/services/">Services</a></nav>';
        if ($conflict && 'desktop' === $device) $menu = '<nav class="other"><a href="/">Different</a></nav>';
        $html .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope=""><div class="body-element-container">'
            . '<div class="header-section"><nav class="top"><a href="/"><img src="/logo.png" alt="Acme" width="80" height="30"></a>' . $menu . '</nav></div>'
            . '<div class="content-box"><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></div><div class="footer-section"><p>© Acme</p></div></div></div>';
    }
    return $html;
};
foreach (array('different', 'identical', 'conflict') as $case) {
    $files = array('index.html' => $document('Home', 'identical' === $case, false), 'services/index.html' => $document('Services', 'identical' === $case, 'conflict' === $case));
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $parts = array_column($plan['template_parts'], 'canonical_block_markup', 'area');
    $assert(isset($parts['footer']), "$case: footer extracts");
    if ('conflict' === $case) {
        $assert(!isset($parts['header']), 'Conflicting desktop headers must remain page-owned');
        continue;
    }
    $header = $parts['header'] ?? '';
    $assert('' !== $header, "$case: header extracts");
    foreach ($plan['pages'] as $page) {
        $assert(!str_contains($page['canonical_block_markup'], 'header-section') && !str_contains($page['canonical_block_markup'], 'footer-section'), "$case: chrome leaves page bodies");
    }
    $assert(2 === substr_count($header, '<header'), "$case: both device scopes retain their header variant");
    if ('different' === $case) {
        $index = new Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($header);
        $assert(2 === count($index->topLevel()), 'Both devices have separate visibility groups');
        foreach ($index->topLevel() as $range) {
            $variant = substr($header, $range['offset'], $range['length']);
            $mobile = str_contains($variant, 'capture-mobile-document');
            $assert($mobile || str_contains($variant, 'capture-desktop-document'), 'Variant retains source visibility class');
            foreach ($plan['menus'] as $menu) {
                $variant = str_replace('{{wordpress-site-plan:navigation:' . $menu['token'] . '}}', $menu['block_markup'], $variant);
            }
            $assert(str_contains($variant, $mobile ? 'Menu' : 'Services'), 'Device visibility owns the matching navigation');
            $assert(!str_contains($variant, $mobile ? '"label":"Services"' : '"label":"Menu"'), 'Opposite device markup does not leak');
        }
    }
}
// Captured device documents also carry the device attribute their gated
// rules and visibility CSS select; each hoisted variant keeps it.
$gated = static fn(string $title): string => preg_replace('/(<div class="data-blocks-engine-capture-(desktop|mobile)-document")/', '$1 data-blocks-engine-capture-device-document="$2"', $document($title, false, false)) ?? '';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $gated('Home'), 'services/index.html' => $gated('Services'))))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$header = array_column($plan['template_parts'], 'canonical_block_markup', 'area')['header'] ?? '';
$index = new Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex($header);
$assert(2 === count($index->topLevel()), 'Gated devices have separate visibility wrappers');
foreach ($index->topLevel() as $range) {
    $variant = substr($header, $range['offset'], $range['length']);
    $device = str_contains($variant, 'capture-mobile-document') ? 'mobile' : 'desktop';
    $assert(1 === preg_match('/^<!--[^>]*-->\s*<div\b[^>]*\bdata-be-d="' . $device . '"[^>]*\bdata-blocks-engine-capture-device-document="' . $device . '"/', $variant), 'The ' . $device . ' header variant keeps its device gate');
}
echo "Unlabeled capture-device shell contract passed.\n";
