<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// Keeping the link outside its blocks preserves the original inheritance
// chain without inserting a browser-coloured anchor inside painted text.
foreach (array('.label{color:rgb(65,65,65)}', '.mid{color:rgb(12,34,56)}', '.wrap{color:#999}', '@media (max-width:600px){.label{color:#fff}}', '@media (min-width:1000px){.label{color:#fff}}') as $css) {
    $html = '<style>' . $css . '</style><a class="wrap" href="/services"><div class="mid"><p class="label">SERVICES</p></div></a>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = $result['serialized_blocks'];
    if (!str_contains($markup, '<a class="wrap" href="/services">') || !str_contains($markup, '>SERVICES</p>') || str_contains($markup, 'blocks-engine-propagated-link-color')) {
        throw new RuntimeException('Linked blocks retain their source paint ancestry without propagated-link overrides.');
    }
    if ('pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')) throw new RuntimeException('Linked container is Gutenberg-valid.');
    if ($markup !== (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks']) throw new RuntimeException('Linked containers are deterministic.');
}
echo "Link wrapper source paint ancestry passed\n";
