<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Matching the parent's initial paint does not make a child's own class
// declaration inherited: it still blocks subsequent parent color edits.
$result = (new ArtifactCompiler())->compileFragment(
    '<div><p class="ink">Explicit ink</p><p>Inherited ink</p></div>',
    'index.html',
    'html',
    array('static_css' => 'body{color:#1d1d1b}.ink{--tw-text-opacity:1;color:rgb(29 29 27 / var(--tw-text-opacity, 1))}')
);
$markup = $result->serializedBlocks;
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
        if ($owned) throw new RuntimeException('Inherited color must remain editable through its parent: ' . $markup);
        ++$checked;
    }
}
if (2 !== $checked) throw new RuntimeException('Expected both paragraph blocks: ' . $markup);
echo "PASS: explicit class text color is block-owned, inherited color stays inherited\n";
