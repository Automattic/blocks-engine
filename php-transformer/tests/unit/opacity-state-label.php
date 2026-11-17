<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Playlist</title>
<style>
.label{opacity:0}
.item:hover .label{opacity:1}
</style>
</head>
<body>
<div class="item"><p>First video</p><div class="label">Current item</div></div>
</body>
</html>
HTML;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(array('path' => 'index.html', 'content' => $html)),
))->toArray();
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) {
        $css .= (string) ($asset['content'] ?? '') . "\n";
    }
}

if ('success' !== $result['status']
    || ! str_contains($result['serialized_blocks'], 'Current item')
    || ! preg_match('/\.label\s*\{\s*opacity:\s*0\s*[;}]/', $css)
    || str_contains($css, 'opacity:1!important')
) {
    fwrite(STDERR, "FAIL: a CSS-revealed state label must retain its authored idle opacity in frontend and editor CSS\n" . $css);
    exit(1);
}

echo "PASS: state label retains opacity:0 without a forced opacity repair\n";
