<?php
declare(strict_types=1);

/**
 * A menu whose every label is the same classed span around plain text states
 * a menu-wide label style: labels save as plain text and the span's rules
 * reach Core's item label, so an item the owner adds later matches (#7275).
 * Menus whose labels differ, partial wraps and semantic or attribute-bearing
 * wrappers keep their markup (#3863, #2400).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$page = static fn (string $services, string $carpet, string $about): string => '<!doctype html><html><head><meta charset="utf-8"><title>Nav label repro</title><style>'
    . 'body{margin:0;font-family:sans-serif}header{background:#0b4f7a;padding:16px}'
    . 'nav ul{list-style:none;margin:0;padding:0;display:flex;gap:24px}nav li{position:relative}nav a{color:#fff;text-decoration:none}'
    . '.item-label{font-size:14px;letter-spacing:.02em}.other-label{font-size:14px;letter-spacing:.02em}'
    . 'nav li ul{display:none;position:absolute;top:100%;left:0;background:#fff;flex-direction:column;padding:8px}nav li:hover ul{display:flex}nav li ul a{color:#0b4f7a}'
    . '</style></head><body><header><nav aria-label="Main"><ul>'
    . '<li><a href="/services">' . $services . '</a><ul><li><a href="/carpet">' . $carpet . '</a></li>'
    . '<li><a href="/tile"><span class="item-label" data-part="dropdown-item-label">Tile Cleaning</span></a></li></ul></li>'
    . '<li><a href="/about">' . $about . '</a></li>'
    . '<li><a href="/contact"><span class="item-label">Contact</span></a></li>'
    . '</ul></nav></header><main><h1>Hello</h1><p>Body text.</p></main></body></html>';

$services = '<span class="item-label">Services</span>';
$carpet = '<span class="item-label" data-part="dropdown-item-label">Carpet Cleaning</span>';
$about = '<span class="item-label">About</span>';

// [services, carpet, about, labels are plain text, label tag kept on /about]
$cases = array(
    'uniform wrapper (issue page)' => array($services, $carpet, $about, true, null),
    'mixed classes' => array($services, $carpet, '<span class="other-label">About</span>', false, '<span'),
    'partial wrap' => array($services, $carpet, '<span class="item-label">Ab</span>out', false, '<span'),
    'semantic tag' => array($services, $carpet, '<strong class="item-label">About</strong>', false, '<strong'),
    'language' => array($services, $carpet, '<span class="item-label" lang="fr">About</span>', false, 'lang='),
    'accessibility' => array($services, $carpet, '<span class="item-label" aria-describedby="hint">About</span>', false, 'aria-describedby='),
);

$failures = array();
foreach ($cases as $case => [$servicesMarkup, $carpetMarkup, $aboutMarkup, $plain, $kept]) {
    $result = (new HtmlTransformer())->transform($page($servicesMarkup, $carpetMarkup, $aboutMarkup), array())->toArray();
    preg_match_all('/<!-- wp:navigation-(?:link|submenu) (\{.*?\}) (?:\/)?-->/s', $result['serialized_blocks'], $matches);
    $labels = array();
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $labels[(string) ($attrs['url'] ?? '')] = (string) ($attrs['label'] ?? '');
    }
    if (5 !== count($labels)) {
        $failures[] = $case . ': expected five menu items, got ' . var_export($labels, true);
        continue;
    }
    $css = implode("\n", array_column($result['assets'], 'content'));
    if ($plain) {
        $expected = array('/services' => 'Services', '/carpet' => 'Carpet Cleaning', '/tile' => 'Tile Cleaning', '/about' => 'About', '/contact' => 'Contact');
        if ($expected !== $labels) $failures[] = $case . ': labels are not plain text: ' . var_export($labels, true);
        if (str_contains($result['serialized_blocks'], 'blocks-engine-role-richtext')) $failures[] = $case . ': engine rich-text markers remain in the menu';
        $reached = false;
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selector, $declarations]) {
            if (str_contains($selector, '.wp-block-navigation-item__label') && str_contains($declarations, 'font-size:14px') && str_contains($declarations, 'letter-spacing:.02em')) $reached = true;
        }
        if (!$reached) $failures[] = $case . ': label typography does not reach .wp-block-navigation-item__label';
    } else {
        if (!str_contains($labels['/about'], (string) $kept) || 'About' !== strip_tags($labels['/about'])) $failures[] = $case . ': /about label lost its markup: ' . $labels['/about'];
        if (!str_contains($labels['/services'], '<span')) $failures[] = $case . ': /services label lost its span: ' . $labels['/services'];
    }
}

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Uniform navigation label wrappers lift to the item label; differing or semantic labels keep their markup\n";
