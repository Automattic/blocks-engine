<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$placeholder = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
$cases = array(
    'sibling spans' => array('<div><span>Pay</span><span><img src="/a.png" alt="visa" data-src="/a.png"></span></div>', '/a.png'),
    'real source wins' => array('<div><span>Pay</span><span><img src="/a.png" alt="visa" data-src="/other.png"></span></div>', '/a.png'),
    'paragraph' => array('<p>Pay <img src="/a.png" alt="visa" data-src="/a.png"></p>', '/a.png'),
    'placeholder' => array('<p>Pay <img src="' . $placeholder . '" alt="visa" data-src="/a.png"></p>', '/a.png'),
    'empty source' => array('<p>Pay <img src="" alt="visa" data-src="/a.png"></p>', '/a.png'),
    'unresolved placeholder' => array('<p>Pay <img src="' . $placeholder . '" alt="visa" data-src="javascript:alert(1)"></p>', null),
    'unresolved empty source' => array('<p>Pay <img src="" alt="visa" data-src=""></p>', null),
);
foreach ( array('data-src', 'data-lazy-src', 'data-original', 'data-image-src', 'data-srcset', 'data-lazy-srcset') as $attribute ) {
    $cases['loaded ' . $attribute] = array('<p>Pay <img class="lazyloaded" src="/a.png" alt="visa" ' . $attribute . '="/other.png"></p>', '/a.png');
}
$findBlock = static function (array $blocks, string $name) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $nested = $findBlock($block['innerBlocks'] ?? array(), $name);
        if ( null !== $nested ) {
            return $nested;
        }
    }
    return null;
};
foreach ( $cases as $name => [$source, $expected] ) {
    $out = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $source),
    ))->toArray();
    $markup = (string) ($out['serialized_blocks'] ?? '');
    $html = $findBlock($out['blocks'] ?? array(), 'core/html');
    if ( null === $expected ) {
        if ( null === $html ) {
            fwrite(STDERR, "FAIL: $name must keep core/html fallback\n$markup\n");
            exit(1);
        }
        continue;
    }
    $paragraph = $findBlock($out['blocks'] ?? array(), 'core/paragraph');
    $content = (string) ($paragraph['attrs']['content'] ?? '');
    if ( null !== $html || str_contains($markup, '<!-- wp:html')
        || ! str_contains($content, 'src="' . $expected . '"')
        || ! str_contains($content, 'alt="visa"')
        || ! str_contains($markup, 'src="' . $expected . '"')
        || preg_match('/\bdata-(?:src|lazy-src|original|image-src|srcset|lazy-srcset)=/', $content . $markup) ) {
        fwrite(STDERR, "FAIL: $name must carry the resolved inline image without lazy metadata\n$markup\n");
        exit(1);
    }
}
fwrite(STDOUT, "inline lazy image rich text tests: passed\n");
