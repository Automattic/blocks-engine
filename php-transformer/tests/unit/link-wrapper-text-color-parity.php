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
// A link pushed into the block holding its text sheds the wrapper's class, id
// and inline style; those stay on the container. The rebuilt anchor inherits
// that paint, and a colour reaching the wrapper by type alone needs no repaint.
foreach (array(
    array('.c{color:rgb(0,0,0)}', 'class="c"', true),
    array('#t{color:rgb(0,0,0)}', 'id="t"', true),
    array('', 'style="color:rgb(0,0,0)"', true),
    array('a{color:rgb(0,0,0)}', '', false),
) as list($css, $attrs, $inherits)) {
    $html = '<style>' . $css . '</style><main><section><div><a ' . $attrs . ' href="/p"><div style="display:flex"><span>Projects</span><svg viewBox="0 0 24 24" width="16" height="16"><path d="M0 0h24v24z"></path></svg></div></a></div><p>Other</p></section></main>';
    $markup = (new HtmlTransformer())->transform($html)->toArray()['serialized_blocks'];
    if (!str_contains($markup, '<a href="/p"><span>Projects</span></a>') || $inherits !== str_contains($markup, 'blocks-engine-propagated-link-color')) {
        throw new RuntimeException('Propagated link paint follows the wrapper identity: ' . $css . $attrs);
    }
}
echo "Link wrapper source paint ancestry passed\n";
