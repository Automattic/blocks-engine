<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A formless label around visible content and a hidden file picker is the
// visible box: its content lowers to native blocks whether or not capture
// proved the picker unrendered, and the picker is never silently lost.
foreach ( array( 'label-wrapped-hidden-file-input', 'label-wrapped-unrendered-file-input' ) as $fixture ) {
    $result = (new HtmlTransformer())->transform(file_get_contents(dirname(__DIR__) . "/fixtures/{$fixture}.html"))->toArray();
    $blocks = (string) $result['serialized_blocks'];
    if ( str_contains($blocks, '<!-- wp:html') || 1 !== preg_match('/<!-- wp:group \{"className":"drop\b.*<!-- wp:image .*Click to choose your files<\/p>.*Files are listed with your request<\/p>/s', $blocks) ) {
        throw new RuntimeException("{$fixture}: the label lowers to a native group with its image and text.");
    }
    $reported = str_contains(json_encode($result['diagnostics']), 'form_control_requires_runtime');
    if ( ! $reported && ! str_contains($blocks, 'type="file"') ) {
        throw new RuntimeException("{$fixture}: the file picker is carried or reported.");
    }
}
echo "Label-wrapped hidden file input contract passed.\n";
