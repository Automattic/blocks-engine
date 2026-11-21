<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Fragment imports must own class paint even without site-plan palette binding.
$fragment = (new ArtifactCompiler())->compileFragment(
    '<div><p class="ink">Explicit ink</p><p>Inherited ink</p><h3>Default heading</h3></div>',
    'index.html',
    'html',
    array('static_css' => 'body{color:#1d1d1b}h3{color:#030712}.ink{--tw-text-opacity:1;color:rgb(29 29 27 / var(--tw-text-opacity, 1))}')
);
if (!preg_match('/<!-- wp:paragraph (\{.*?\}) -->\s*<p[^>]*has-text-color[^>]*>Explicit ink<\/p>/s', $fragment->serializedBlocks, $match)
    || !isset(json_decode($match[1], true)['style']['color']['text'])) {
    throw new RuntimeException('Fragment class color must have native support: ' . $fragment->serializedBlocks);
}
if (!str_contains($fragment->serializedBlocks, '<!-- wp:paragraph -->')
    || !str_contains($fragment->serializedBlocks, '<!-- wp:heading {"level":3} -->')) {
    throw new RuntimeException('Inherited paint and tag defaults must remain untouched: ' . $fragment->serializedBlocks);
}

$caption = (new ArtifactCompiler())->compileFragment(
    '<figure><img src="portrait.jpg" alt="Portrait"><figcaption><span class="ink">Caption ink</span></figcaption></figure>',
    'index.html',
    'html',
    array('static_css' => '.ink{color:#1d1d1b}')
)->serializedBlocks;
if (!preg_match('/<span[^>]*style="[^"]*color:[^"]+"[^>]*>Caption ink<\/span>/', $caption)) {
    throw new RuntimeException('Image caption text must carry its own color: ' . $caption);
}

// Palette binding must own stable class paint without projecting unrelated
// tag defaults inline or freezing genuinely inherited paragraph color.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<html><head><link rel="stylesheet" href="s.css"></head><body><main><p class="ink">Explicit ink</p><p>Inherited ink</p><h3>Default heading</h3><p class="accent">Different ink</p></main></body></html>'),
    array('path' => 's.css', 'content' => 'body{color:#1d1d1b}h3{color:#030712}.accent{color:#c00}.ink{--tw-text-opacity:1;color:rgb(29 29 27 / var(--tw-text-opacity, 1))}'),
)))->toArray();
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'], 'canonical_block_markup'));
preg_match_all('/<!-- wp:paragraph(?: (\{.*?\}))? -->\s*<p\b([^>]*)>(.*?)<\/p>/s', $markup, $paragraphs, PREG_SET_ORDER);
$checked = 0;
foreach ($paragraphs as $paragraph) {
    $attrs = json_decode($paragraph[1] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $owned = isset($attrs['textColor']) || isset($attrs['style']['color']['text']);
    if ('Explicit ink' === $paragraph[3]) {
        if (!$owned || !str_contains($paragraph[2], 'has-text-color')) {
            throw new RuntimeException('Class-owned color needs native block color support: ' . $markup);
        }
        ++$checked;
    } elseif ('Inherited ink' === $paragraph[3]) {
        if ($owned) throw new RuntimeException('Do not change inherited color or unrelated source paint: ' . $markup);
        ++$checked;
    }
}
if (2 !== $checked) throw new RuntimeException('Expected all paragraph blocks: ' . $markup);
if (!str_contains($markup, '<!-- wp:heading {"level":3} -->')) {
    throw new RuntimeException('Tag-default heading paint must not acquire inline color: ' . $markup);
}
echo "PASS: explicit class text color is block-owned, inherited color stays inherited\n";
