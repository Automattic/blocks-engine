<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = file_get_contents(dirname(__DIR__) . '/fixtures/custom-element-dialog.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$failures = array();
foreach ($result['fallbacks'] as $finding) {
    if ('html_unsupported_element' === ($finding['diagnostic_code'] ?? '')
        && in_array($finding['tag'] ?? '', array('side-drawer', 'site-popover'), true)
    ) {
        $failures[] = $finding['tag'] . ' must compile through the container path';
    }
}
$markup = $result['serialized_blocks'];
if (str_contains($markup, '<!-- wp:html')) $failures[] = 'Dialog wrappers must not emit core/html';
if (!str_contains($markup, '<!-- wp:navigation ') || !str_contains($markup, '"url":"/a"')) {
    $failures[] = 'Drawer navigation must compile to native navigation blocks';
}
if (!str_contains($markup, '<!-- wp:search ') || !str_contains($markup, '"label":"Query"')) {
    $failures[] = 'Popover form must compile through the native search recognizer';
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
if (array() !== $failures) exit(1);
echo "OK: custom-element dialog containers\n";
