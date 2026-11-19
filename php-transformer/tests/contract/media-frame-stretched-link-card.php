<?php
declare(strict_types=1);

// The bootstrap loads Composer when installed and its own source autoloader
// otherwise, so the contract runs from a checkout without vendor/.
require dirname(__DIR__, 2) . '/php-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #5249: an editorial card whose image sits in a hover-scale <span> frame
// inside its visual region is the same card as one whose image sits directly
// in that region. The heading owns the one editable destination instead of
// the card staying a custom/layout-shell <a>.
$card = static function (string $image): array {
    $markup = (new HtmlTransformer())->transform(
        '<main><a class="group card" href="/booking/school/" target="_blank" rel="noopener">'
        . '<div class="visual">' . $image . '</div>'
        . '<div class="body"><h3>School Catering</h3><p>Tell us about your school.</p><span>Book this service<span>→</span></span></div>'
        . '</a></main>'
    )->toArray()['serialized_blocks'];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $markup);
    return array($markup, new DOMXPath($dom));
};

foreach (array(
    'span frame' => '<span class="frame relative"><img src="school.webp" class="absolute" alt="School"></span>',
    'span frame around picture' => '<span class="frame"><picture><img src="school.webp" alt="School"></picture></span>',
) as $case => $image) {
    [$markup, $xpath] = $card($image);
    if (str_contains($markup, '/layout-shell')
        || 1 !== $xpath->query('//div[contains(@class,"blocks-engine-stretched-link")]')->length
        || 1 !== $xpath->query('//h3/a[@href="/booking/school/" and @target="_blank" and @rel="noopener"]')->length
        || 1 !== $xpath->query('//a')->length
        || 1 !== $xpath->query('//img[@src="school.webp"]')->length) {
        throw new RuntimeException("A card whose image has a $case must expose its destination on the heading: " . $markup);
    }
}

// A span that also carries text is phrasing, not a media frame: the card
// keeps its source wrapper.
[$markup] = $card('<span class="frame"><img src="school.webp" alt="">New</span>');
if (!str_contains($markup, '/layout-shell')) {
    throw new RuntimeException('A captioned image span must retain the source wrapper: ' . $markup);
}
echo "Media frame stretched-link card contract passed.\n";
