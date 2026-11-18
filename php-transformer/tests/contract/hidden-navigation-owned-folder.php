<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #5822: a hidden phone-row submenu is not the separate burger's overlay.
$html = file_get_contents(__DIR__ . '/fixtures/hidden-navigation-owned-folder.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
)))->toArray();
$markup = $result['serialized_blocks'];
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$xpath = new DOMXPath($dom);
$slots = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," header-display-mobile ")]//*[contains(concat(" ",normalize-space(@class)," ")," header-burger ")]');
$projected = array();
foreach ($slots as $slot) {
    foreach ($slot->childNodes as $child) {
        if ($child instanceof DOMComment && preg_match('/^ wp:navigation (\{.*\}) /s', $child->data, $match)) {
            $projected[] = json_decode($match[1], true);
        }
    }
}
$failures = array();
if (1 !== count($projected) || 'mobile' !== ($projected[0]['overlayMenu'] ?? '')) {
    $failures[] = 'Exactly one native opener must occupy the phone burger slot.';
}
$styles = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '') && !str_contains($asset['path'], 'editor-static')), 'content'));
$toggleClass = '';
if (preg_match('/blocks-engine-native-navigation-toggle-[a-f0-9]+/', $projected[0]['className'] ?? '', $match)) $toggleClass = $match[0];
if ('' === $toggleClass || !str_contains($styles, '.' . $toggleClass . '>.wp-block-navigation__responsive-container-open')
    || !str_contains($styles, 'width:35px!important') || !str_contains($styles, 'height:35px!important')
    || !str_contains($styles, '@media(max-width:799px)')) {
    $failures[] = 'The phone opener must retain its visible 35px source slot and collapse boundary.';
}
$slotHtml = $slots->length ? $dom->saveHTML($slots->item(0)) : '';
foreach (array('/', '/design/', '/web/', '/about/', '/contact/') as $url) {
    if (!str_contains($slotHtml, '"url":"' . $url . '"')) {
        $failures[] = 'The phone navigation must contain the overlay destination ' . $url;
    }
}
if (str_contains($markup, 'header-menu-controls-control') || str_contains($markup, 'class="wp-block-group header-menu ')) {
    $failures[] = 'The resolved overlay and its obsolete back control must be absorbed.';
}
$quality = $result['source_reports']['wordpress_site_plan']['quality'] ?? null;
if (!is_array($quality)) $failures[] = 'Compilation must expose site-plan quality findings.';
foreach ($quality['fallbacks'] ?? array() as $fallback) {
    if ('interactive_control_behavior_lost' === ($fallback['reason'] ?? '') && str_contains(json_encode($fallback), 'back')) {
        $failures[] = 'The absorbed folder back control must not count as unsupported behavior.';
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
