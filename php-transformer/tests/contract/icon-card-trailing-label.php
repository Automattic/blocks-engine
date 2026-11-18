<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A separate icon region and trailing phrasing label are not controls. The
// heading must still own the one editable destination (#5249).
$icon = '<svg viewBox="0 0 24 24"><path d="M3 12h18"></path></svg>';
foreach (array('→', 'Read more ' . $icon) as $label) {
    $content = '<div class="icon">' . $icon . '</div>'
        . '<div><h3>Service details</h3><p>Find out about our service.</p></div>'
        . '<span class="arrow">' . $label . '</span>';
    foreach (array(false, true) as $interactive) {
        $card = '<a class="group card" href="/service/" target="_blank" rel="noopener">'
            . $content . ($interactive ? '<span role="button">Choose</span>' : '') . '</a>';
        $out = (new HtmlTransformer())->transform('<style>.card{display:flex;gap:16px}.arrow{margin-left:auto}</style><main>' . $card . '</main>')->toArray();
        $markup = $out['serialized_blocks'];
        if ($interactive) {
            if (!str_contains($markup, '/layout-shell')) {
                throw new RuntimeException('Interactive trailing labels must retain the source wrapper.');
            }
            continue;
        }
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $markup);
        $xpath = new DOMXPath($dom);
        if (str_contains($markup, '/layout-shell')
            || 1 !== $xpath->query('//div[contains(@class,"blocks-engine-stretched-link")]')->length
            || 1 !== $xpath->query('//h3/a[@href="/service/" and @target="_blank" and @rel="noopener"]')->length
            || 1 !== $xpath->query('//a')->length
            || 1 !== $xpath->query('//span[contains(@class,"arrow")]')->length) {
            throw new RuntimeException('A decorative trailing label must not prevent a native heading-linked card: ' . $markup);
        }
    }
}
echo "Icon card trailing label contract passed.\n";
