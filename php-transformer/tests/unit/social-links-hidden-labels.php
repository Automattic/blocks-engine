<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A social link names itself through an SVG <title> and a screen-reader-only
// label; only text a reader sees makes the row print visible labels.
$link = static fn (string $service, string $label): string => '<li><a href="https://www.' . $service . '.com/example/" title="' . ucfirst($service) . '">'
    . '<svg role="img" viewBox="0 0 24 24"><title>' . ucfirst($service) . ' icon</title><path d="M0 0h24v24H0z"/></svg>' . $label . '</a></li>';
$cases = array(
    'clipped label' => array('<span class="hidden-label">%s</span>', false),
    'visible label' => array('<span>%s</span>', true),
);
foreach ($cases as $name => [$label, $visible]) {
    $source = '<style>.hidden-label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}</style>'
        . '<p>Follow us</p><ul class="socials">' . $link('facebook', sprintf($label, 'Facebook')) . $link('instagram', sprintf($label, 'Instagram')) . '</ul>';
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $rows = array_values(array_filter($result['blocks'], static fn (array $block): bool => 'core/social-links' === $block['blockName']));
    if (1 !== count($rows)) {
        throw new RuntimeException("$name: the icon list becomes one native social-links row.");
    }
    if ($visible !== ! empty($rows[0]['attrs']['showLabels']) || $visible === str_contains((string) ($rows[0]['attrs']['className'] ?? ''), 'is-style-logos-only')) {
        throw new RuntimeException("$name: visible labels must follow what the source paints, not its accessible text.");
    }
    if (array('Facebook', 'Instagram') !== array_column(array_column($rows[0]['innerBlocks'], 'attrs'), 'label')) {
        throw new RuntimeException("$name: each link keeps its accessible name as its label.");
    }
}
echo "Social links hidden labels passed.\n";
