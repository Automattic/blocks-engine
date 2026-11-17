<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (string $css, string $body, string $script = ''): array {
    return (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Playlist</title><style>' . $css . '</style></head><body>' . $body . $script . '</body></html>'),
    ))->toArray();
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach (array(
    '.item:hover .label',
    '.item:focus .label',
    '.item:focus-within .label',
    '.item:focus-visible .label',
    '.item:active .label',
    '.item:hover:focus .label',
    'input:checked + .item .label',
) as $selector) {
    $result = $compile('.label{opacity:0}' . $selector . '{opacity:1}', '<input type="checkbox"><div class="item" tabindex="0"><p>First video</p><div class="label">Current item</div></div>');
    $assert('success' === $result['status'] && str_contains($result['serialized_blocks'], 'Current item'), $selector . ': state content survives compilation');
    $css = '';
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? '')) {
            $css .= (string) ($asset['content'] ?? '') . "\n";
        }
    }
    $assert(1 === preg_match('/\.label\s*\{\s*opacity:\s*0\s*[;}]/', $css), $selector . ': authored resting opacity survives');
    $assert(str_contains($css, $selector . '{opacity:1}'), $selector . ': authored interaction survives');
    $assert(! str_contains($css, 'opacity:1!important'), $selector . ': neither frontend nor editor forces the label visible');
}

// A state rule for a different element, or one that does not reveal opacity,
// must not suppress the existing script-entrance recovery path.
foreach (array('', '.other:hover{opacity:1}', '.reveal:hover{color:red}', '.reveal:hover{opacity:0}') as $stateCss) {
    $result = $compile(
        '.reveal{opacity:0;transform:translateY(2rem)}.reveal.is-visible{opacity:1;transform:none}' . $stateCss,
        '<main><h2 class="reveal">Entrance heading</h2><p class="reveal">Entrance copy</p></main>',
        '<script>new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting)entry.target.classList.add("is-visible")})).observe(document.querySelector(".reveal"));</script>'
    );
    $assert('success' === $result['status'], 'script entrance compiles');
    foreach (array('engine-support-after-author', 'editor-static-state') as $path) {
        $css = implode('', array_map(static fn (array $asset): string => str_contains((string) ($asset['path'] ?? ''), $path) ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
        $assert(str_contains($css, ':root .reveal{opacity:1!important;transform:none!important}'), $path . ': script-only entrance content is settled visible without aria attributes');
    }
}

echo "PASS: CSS state labels retain idle opacity while script entrances settle visible\n";
