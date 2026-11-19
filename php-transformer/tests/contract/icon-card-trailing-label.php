<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #5249: an icon card whose last child is a text-only arrow label is not a
// control. The heading owns the one editable destination instead of the card
// staying a custom/layout-shell <a>. Leading phrasing (an eyebrow) and
// interactive labels keep their source wrapper.
$icon = '<div class="icon"><svg viewBox="0 0 24 24"><path d="M3 12h18"></path></svg></div>';
$body = '<div><h3>Service details</h3><p>Find out about our service.</p></div>';
$transform = static function (string $content): string {
    $card = '<a class="group card" href="/service/" target="_blank" rel="noopener">' . $content . '</a>';
    return (new HtmlTransformer())->transform('<style>.card{display:flex;gap:16px}.arrow{margin-left:auto}</style><main>' . $card . '</main>')->toArray()['serialized_blocks'];
};

$markup = $transform($icon . $body . '<span class="arrow">→</span>');
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $markup);
$xpath = new DOMXPath($dom);
if (str_contains($markup, '/layout-shell')
    || 1 !== $xpath->query('//div[contains(@class,"blocks-engine-stretched-link")]')->length
    || 1 !== $xpath->query('//h3/a[@href="/service/" and @target="_blank" and @rel="noopener"]')->length
    || 1 !== $xpath->query('//a')->length
    || 1 !== $xpath->query('//span[contains(@class,"arrow") and .="→"]')->length) {
    throw new RuntimeException('A trailing arrow label must not prevent a native heading-linked card: ' . $markup);
}

foreach (array(
    'eyebrow' => '<span class="eyebrow">New</span>' . $icon . $body,
    'interactive label' => $icon . $body . '<span class="arrow" role="button">→</span>',
) as $case => $content) {
    if (!str_contains($transform($content), '/layout-shell')) {
        throw new RuntimeException("A card with an $case must retain its source wrapper.");
    }
}
echo "Icon card trailing label contract passed.\n";
