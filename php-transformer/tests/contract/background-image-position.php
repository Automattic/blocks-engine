<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

foreach (array(
    'inline' => array('94.54% 89.47%', false),
    'author rule' => array('94.54% 89.47%', true),
    'keywords' => array('right bottom', false),
    'contain' => array('right bottom', true, 'contain'),
    'absent inline' => array('', false),
    'absent author rule' => array('', true),
    'absent contain' => array('', true, 'contain'),
) as $label => $case) {
    [$position, $authorRule] = $case;
    $style = 'background-image:url(x.jpg);background-size:' . ($case[2] ?? 'cover');
    if ('' !== $position) {
        $style .= ';background-position:' . $position;
    }
    $html = $authorRule
        ? '<style>.photo{' . $style . '}</style><div class="photo"></div>'
        : '<div style="' . $style . '"></div>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) $result['serialized_blocks'];
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    if (!preg_match('/<figure\b[^>]*class="([^"]*)"[^>]*>\s*<img\b/', $markup, $figure)
        || !str_contains($figure[1], 'blocks-engine-background-image')) {
        throw new RuntimeException($label . ': background must materialize as core/image.');
    }
    $expectedPosition = '' === $position ? '0% 0%' : $position;
    if (!preg_match('/\b(be-inline-geometry-[a-f0-9]+)\b/', $figure[1], $carrier)
        || !str_contains($css, '.' . $carrier[1] . '{object-position:' . $expectedPosition . '}.' . $carrier[1] . '>img,.' . $carrier[1] . '>a>img{object-position:inherit}')) {
        throw new RuntimeException($label . ': image geometry carrier must preserve object-position.');
    }
}
echo "Background image position contract passed.\n";
