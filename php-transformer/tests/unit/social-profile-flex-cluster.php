<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-profile-flex-cluster.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," follow ")]/ul[contains(concat(" ",normalize-space(@class)," ")," links ")]')->length) {
    throw new RuntimeException('The native social list retains the direct-child cluster topology.');
}
if (!preg_match('/(?:^|[},])\s*\.follow\s*>\s*\.links\s*(?:,[^{]*)?\{display:flex;gap:7\.5px\}/', $css)) {
    throw new RuntimeException('The original direct-child selector must retain authored flex and gap alongside carrier forms.');
}
if (!str_contains($css, 'ul.blocks-engine-social-source-non-list.wp-block-social-links > li.wp-block-social-link{display:flex}')) {
    throw new RuntimeException('Non-list wrappers blockify anchors with specificity above Core inline-block items.');
}
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'candidate' => $markup, 'css' => $css), JSON_THROW_ON_ERROR);
} else {
    echo "Social profile flex cluster passed.\n";
}
