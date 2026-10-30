<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// core/image keeps only the image inside its link, so an unnamed image must
// carry the name the source gave the link some other way.
$cases = array(
    'clipped label'  => array('<a href="/" title="Go home"><span class="sr-only">Acme</span><img src="logo.png"></a>', 'Acme'),
    'aria-label'     => array('<a href="/" aria-label="Acme home"><img src="logo.png" alt=""></a>', 'Acme home'),
    'title only'     => array('<a href="/" title="Go home"><img src="logo.png"></a>', 'Go home'),
    'aria-hidden'    => array('<a href="/" title="Go home"><span aria-hidden="true">→</span><img src="logo.png"></a>', 'Go home'),
    'named image'    => array('<a href="/"><span class="sr-only">Acme</span><img src="logo.png" alt="Acme logo"></a>', 'Acme logo'),
);
foreach ($cases as $name => [$link, $alt]) {
    $source = '<style>.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}.bar{display:flex}</style>'
        . '<header><nav class="bar">' . $link . '<ul><li><a href="/a">About</a></li><li><a href="/b">Contact</a></li></ul></nav></header>';
    $markup = (new HtmlTransformer())->transform($source)->toArray()['serialized_blocks'];
    if (1 !== preg_match('/<!-- wp:image .*?<img [^>]*alt="([^"]*)"/s', $markup, $match)) {
        throw new RuntimeException("$name: the linked logo becomes core/image.\n$markup");
    }
    if ($alt !== html_entity_decode($match[1])) {
        throw new RuntimeException("$name: expected alt \"$alt\", got \"{$match[1]}\".");
    }
}
echo "Linked image link name passed.\n";
