<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Fixture for social-links-glyph-paint-browser.mjs: prints the source page,
// the compiled blocks and the compiled CSS as JSON.
$path = 'M22 12.061C22 6.505 17.523 2 12 2S2 6.505 2 12.061c0 5.022 3.657 9.184 8.438 9.939v-7.03h-2.54v-2.91h2.54V9.845c0-2.522 1.492-3.915 3.777-3.915 1.094 0 2.238.197 2.238.197v2.476h-1.26c-1.243 0-1.63.775-1.63 1.57v1.888h2.773l-.443 2.908h-2.33V22c4.78-.755 8.437-4.917 8.437-9.939z';
$html = '<section class="social"><h2>Follow</h2><div class="row"><a class="x-el-a c1-2z" href="https://www.facebook.com/example" aria-label="Facebook Social Link"><svg class="c1-60" viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path fill-rule="evenodd" d="' . $path . '"></path></svg></a></div></section>';
$css = '.x .x-el-a{color:inherit}.x .c1-2z{color:#969696}.x .c1-60{color:#e2e2e2}';
$page = '<div class="x">' . $html . '</div>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="style.css">' . $page),
        array('path' => 'style.css', 'kind' => 'css', 'content' => $css),
    ),
))->toArray();
$compiled = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $compiled .= ($asset['content'] ?? '') . "\n";
}
if (!str_contains($result['serialized_blocks'], 'wp:social-link ')) throw new RuntimeException('Expected core/social-link: ' . $result['serialized_blocks']);
echo json_encode(array('source' => '<style>' . $css . '</style>' . $page, 'candidate' => '<div class="x">' . $result['serialized_blocks'] . '</div>', 'css' => $compiled), JSON_THROW_ON_ERROR);
