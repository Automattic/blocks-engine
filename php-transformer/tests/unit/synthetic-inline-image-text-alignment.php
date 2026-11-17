<?php
declare(strict_types=1);

/** Inline image alignment belongs to its existing group, not its transparent figure. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
foreach (array('center', 'right', 'left') as $alignment) {
    foreach (array(false, true) as $linked) {
        $image = '<img src="a.jpg" width="207" height="320">';
        $content = $linked ? '<a href="a.jpg" style="margin-left:1em;margin-right:1em">' . $image . '</a>' : $image;
        $result = (new HtmlTransformer())->transform(
            '<div class="separator" style="clear:both;text-align:' . $alignment . '">' . $content . '</div>',
            array()
        )->toArray();
        $group = $result['blocks'][0] ?? array();
        $imageBlock = $group['innerBlocks'][0] ?? array();
        $css = '';
        foreach ($result['assets'] ?? array() as $asset) {
            if ('css' === ($asset['kind'] ?? '')) {
                $css .= $asset['content'] ?? '';
            }
        }
        $carriesAlignment = false;
        foreach (explode(' ', $group['attrs']['className'] ?? '') as $className) {
            if ('' !== $className && (str_contains($css, '.' . $className . '{text-align:' . $alignment . '}')
                || str_contains($css, '.' . $className . '{text-align:' . $alignment . ' !important}'))) {
                $carriesAlignment = true;
            }
        }
        $checks = array(
            'existing group carries alignment in its CSS' => 'core/group' === ($group['blockName'] ?? '') && $carriesAlignment,
            'native image retains its transparent figure' => 'core/image' === ($imageBlock['blockName'] ?? '')
                && str_contains($imageBlock['attrs']['className'] ?? '', 'blocks-engine-synthetic-image-figure-inline'),
            'image does not acquire unsupported text alignment' => !isset($imageBlock['attrs']['style']['typography']['textAlign'])
                && !isset($imageBlock['attrs']['align']),
            'dimensions survive' => '207px' === ($imageBlock['attrs']['width'] ?? '') && '320px' === ($imageBlock['attrs']['height'] ?? ''),
            'link survives' => $linked === ('custom' === ($imageBlock['attrs']['linkDestination'] ?? '')),
        );
        foreach ($checks as $message => $ok) {
            if (!$ok) {
                ++$failures;
                fwrite(STDERR, 'FAIL: ' . $alignment . ($linked ? ' linked: ' : ' bare: ') . $message . PHP_EOL);
            }
        }
    }
}

if (0 < $failures) {
    exit(1);
}
echo "synthetic inline image text alignment passed\n";
