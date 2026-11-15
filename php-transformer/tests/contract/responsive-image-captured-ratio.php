<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #5947: the inline ratio is capture evidence, not permission to replace the
// author's responsive height with core/image's inline height:auto.
$fixture = '<style>.card{position:relative;min-height:350px}.card>img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}@media (min-width:900px){.card>img{height:280px;position:static}}</style>'
    . '<article class="card"><img src="a.webp" width="1200" height="788" style="aspect-ratio:577/408" alt="A"><div><h3>Title</h3></div></article>';

foreach (array(
    'issue fixture' => $fixture,
    'neutral renamed subject' => str_replace('card', 'panel', $fixture),
    'different breakpoint and height' => str_replace(array('900px', '280px'), array('1024px', '310px'), $fixture),
) as $name => $source) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $source),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $assert = static function (bool $condition, string $message) use ($name, $markup, $css): void {
        if (! $condition) throw new RuntimeException($name . ': ' . $message . "\n" . $markup . "\n" . $css);
    };
    $assert(1 === preg_match('/<figure\b[^>]*>\s*<img\b[^>]*>/', $markup), 'editable image retains its native figure and leaf');
    $assert(str_contains($markup, 'aspect-ratio:577/408'), 'capture ratio survives on the image');
    $assert(! str_contains($markup, 'height:auto'), 'no generated inline auto height overrides the author');
    $assert(! str_contains($markup, 'width:100%'), 'coupled native width cannot manufacture an inline auto height');
    $assert(! preg_match('/height:100%\s*!important/', $css), 'no blanket important figure fill overrides the breakpoint');
    $assert(1 === preg_match('/\.wp-block-image\s*> img[^{}]*\{[^}]*height:100%/', $css), 'base child-image rule reaches the lowered leaf');
    $height = str_contains($source, '310px') ? '310px' : '280px';
    $breakpoint = str_contains($source, '1024px') ? '1024px' : '900px';
    $assert(str_contains($css, '@media (min-width:' . $breakpoint . ')'), 'author breakpoint survives');
    $assert(str_contains($css, 'height:' . $height . ';position:static'), 'responsive figure owns author height and position');
    $assert(1 === preg_match('/@media.*\.wp-block-image\s*> img[^{}]*\{[^}]*height:inherit/', $css), 'responsive image follows the figure height instead of its captured ratio');
}

echo "responsive-image-captured-ratio: ok\n";
