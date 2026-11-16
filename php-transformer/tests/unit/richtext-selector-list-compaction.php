<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;

$a = 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-aaaaaaaaaaaa-0;"]';
$b = 'span[data-blocks-engine-richtext-marker="blocks-engine-richtext-bbbbbbbbbbbb-0"]';
$scope = ':where(:root.is-route) ';
$css = $scope . ':where(' . $a . '):not(.excluded)::before,' . $scope . ':where(' . $b . '):not(.excluded)::before{margin:0}';
$expected = $scope . ':where(' . $a . ',' . $b . '):not(.excluded)::before{margin:0}';
if ($expected !== CssSelectorListCompactor::stylesheet($css)) {
    throw new RuntimeException('RichText addresses with identical context and pseudo-element must share one zero-specificity list');
}
foreach (array(
    ':where(' . $a . ')::before,:where(' . $b . ')::after{margin:0}',
    '.route-a :where(' . $a . '),.route-b :where(' . $b . '){margin:0}',
    ':not(:where(' . $a . ')),:not(:where(' . $b . ')){margin:0}',
) as $distinct) {
    if ($distinct !== CssSelectorListCompactor::stylesheet($distinct)) {
        throw new RuntimeException('Distinct pseudo-elements, route scopes and negations must not be merged');
    }
}
echo "RichText selector list compaction passed\n";
