<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

// The editor static-state sheet gives a hidden image carrier a box so the
// image can be selected. That repair must reach only carriers an author rule
// hides: an unrelated hidden rule elsewhere on the page must not force a flex
// icon badge to `display:block`, which drops its centring in the editor.
$transform = static function (string $html): array {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
    return array($result['serialized_blocks'], $css);
};

$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium'));
if ('' === $chrome) throw new RuntimeException('Browser required for editor image carrier contract.');
$dir = sys_get_temp_dir() . '/editor-image-carrier-' . bin2hex(random_bytes(6));
mkdir($dir);
$probe = static function (string $markup, string $css, string $script) use ($chrome, $dir): string {
    // Gutenberg serves imported images; any decodable box will do here.
    $markup = (string) preg_replace('/src="[^"]*"/', 'src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2218%22 height=%2218%22/%3E"', $markup);
    file_put_contents($dir . '/test.html', '<!doctype html><style>' . $css . '</style>'
        . '<div class="editor-styles-wrapper">' . $markup . '</div><script>' . $script . '</script>');
    $output = (string) shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome)
        . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --window-size=1200,800 --dump-dom --virtual-time-budget=1000 '
        . escapeshellarg('file://' . $dir . '/test.html') . ' 2>/dev/null');
    return preg_match('/data-probe="([^"]*)"/', $output, $match) ? html_entity_decode($match[1]) : 'no probe: ' . substr($output, 0, 300);
};

try {
    [$markup, $css] = $transform((string) file_get_contents(__DIR__ . '/../fixtures/editor-image-carrier-flex-centering.html'));
    $centring = $probe($markup, $css, 'const b=document.querySelector(".notice-icon"),f=b.querySelector("figure").getBoundingClientRect(),r=b.getBoundingClientRect();'
        . 'document.body.dataset.probe=getComputedStyle(b).display+","+Math.round(Math.abs((f.left+f.width/2)-(r.left+r.width/2)))+","+Math.round(Math.abs((f.top+f.height/2)-(r.top+r.height/2)))');
    if ('flex,0,0' !== $centring) {
        throw new RuntimeException('A visible flex image carrier must keep its display and centre its image in the editor: ' . $centring);
    }

    // The carrier the source hides until a runtime reveals it still gets a
    // selectable box in the editor.
    [$markup, $css] = $transform('<style>.badge{display:flex;width:40px;height:40px;align-items:center;justify-content:center}'
        . '.icon{display:none;width:16px;height:16px}.icon svg{display:block;width:16px;height:16px}</style>'
        . '<main><div class="badge"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path d="M0 8h16"/></svg></div>'
        . '<a href="/about"><span>Read more</span><div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path d="M0 8h16"/></svg></div></a></main>');
    $revealed = $probe($markup, $css, 'const i=document.querySelector(".icon"),b=document.querySelector(".badge");'
        . 'document.body.dataset.probe=getComputedStyle(i).display+","+(i.querySelector("figure").getBoundingClientRect().width>0)+","+getComputedStyle(b).display');
    if ('block,true,flex' !== $revealed) {
        throw new RuntimeException('Only the author-hidden image carrier is given a box in the editor: ' . $revealed);
    }
} finally {
    shell_exec('rm -rf ' . escapeshellarg($dir));
}
echo "editor-image-carrier-flex-centering: ok\n";
