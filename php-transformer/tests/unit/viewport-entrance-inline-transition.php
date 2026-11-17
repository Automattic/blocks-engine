<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The capture activator overwrites then removes the transition shorthand at
// startup. An isolated inline longhand must not escape that reset into CSS.
$state = htmlspecialchars((string) json_encode(array(
    'attributes' => array('class' => array('before' => 'reveal', 'after' => 'reveal in')),
    'threshold' => array(0.15), 'rootMargin' => '0px', 'repeat' => false,
)), ENT_QUOTES);
foreach (array(false, true) as $entrance) {
    $result = (new HtmlTransformer())->transform(
        '<style>.reveal{opacity:0;transition:opacity .8s}.reveal.in{opacity:1}</style>'
        . '<main><figure class="reveal in" style="transition-delay:320ms"'
        . ($entrance ? ' data-blocks-engine-capture-viewport-entrance="' . $state . '"' : '')
        . '><img src="https://example.com/photo.jpg" width="300" height="200" alt="Photo"><figcaption>Caption</figcaption></figure></main>',
        array()
    )->toArray();
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    $carried = (bool) preg_match('/\.be-inline-geometry-[^{]+\{[^}]*transition-delay\s*:\s*320ms/', $css);
    if ($carried === $entrance) {
        throw new RuntimeException($entrance
            ? 'Replay-cleared transition delay became persistent CSS.'
            : 'Ordinary inline transition delay was lost.');
    }
}
echo "viewport entrance inline transition: ok\n";
