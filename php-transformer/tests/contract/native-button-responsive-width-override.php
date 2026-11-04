<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if ( ! $ok ) {
        throw new RuntimeException($message);
    }
};
$source = file_get_contents(dirname(__DIR__) . '/fixtures/header-button-responsive-width.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$markup = (string) $result['serialized_blocks'];

// The unconditional width:100% compatibility rule is boosted to outrank
// Core's button defaults. Its conditional width:auto release must use the same
// restatement, or the base wins regardless of the authored breakpoint order.
$media = null;
foreach ( preg_split('/(?=@media)/', $css) ?: array() as $chunk ) {
    if ( str_starts_with($chunk, '@media') && str_contains($chunk, 'min-width:1024px') ) {
        $media = $chunk;
        break;
    }
}
$assert(is_string($media), 'The desktop breakpoint survives projection.');
$assert(
    1 === preg_match('/:where\((\.blocks-engine-control-[a-f0-9]{12}-\d+)\)\1\1[^{}]*>\s*:where\(\.wp-block-button__link\)[^{}]*\{[^{}]*width:auto/', $media),
    'The conditional width release is restated at the boosted compatibility weight.'
);

// Compare the public transform output to the reduced source page in a browser.
// WordPress renders navigation dynamically; materialize its saved block shape.
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ( '' !== $chrome ) {
    $rendered = preg_replace_callback('/<!-- wp:navigation (\{.*?\}) -->(.*?)<!-- \/wp:navigation -->/s', static function (array $match): string {
        $attrs = json_decode($match[1], true);
        $items = '';
        preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $match[2], $links);
        foreach ( $links[1] as $json ) {
            $link = json_decode($json, true);
            $anchor = $link['metadata']['blocksEngineNavigationAnchor'] ?? array();
            $classes = 'wp-block-navigation-item ' . (string) ($link['className'] ?? '');
            $items .= '<li class="' . htmlspecialchars($classes, ENT_QUOTES) . '"><a class="wp-block-navigation-item__content ' . htmlspecialchars((string) ($anchor['className'] ?? ''), ENT_QUOTES) . '" href="' . htmlspecialchars((string) $link['url'], ENT_QUOTES) . '">' . htmlspecialchars((string) $link['label'], ENT_QUOTES) . '</a></li>';
        }
        return '<nav class="wp-block-navigation ' . htmlspecialchars((string) ($attrs['className'] ?? ''), ENT_QUOTES) . '"><ul class="wp-block-navigation__container">' . $items . '</ul></nav>';
    }, $markup);
    $assert(is_string($rendered) && ! str_contains($rendered, '<!-- wp:navigation '), 'The saved navigation shape is materialized for browser measurement.');

    $fingerprint = static function (int $width) use ($source, $css, $rendered, $chrome): array {
        $documents = array(
            '<!doctype html><html><head><style>.wp-block-navigation{display:flex}.wp-block-navigation__container{display:flex;list-style:none}.wp-block-navigation-item__content{display:block}</style>' . $source . '</body></html>',
            '<!doctype html><html><head><style>.wp-block-navigation{display:flex}.wp-block-navigation__container{display:flex;list-style:none}.wp-block-navigation-item__content{display:block}' . $css . '</style></head><body>' . $rendered . '</body></html>',
        );
        $html = '<!doctype html><body>';
        foreach ( $documents as $document ) {
            $html .= '<iframe style="border:0;width:' . $width . 'px;height:2000px" srcdoc="' . htmlspecialchars($document, ENT_QUOTES) . '"></iframe>';
        }
        $html .= <<<'JS'
<script>onload=()=>{document.body.dataset.result=JSON.stringify([...document.querySelectorAll('body>iframe')].map(f=>{const d=f.contentDocument,w=f.contentWindow;const b=d.querySelector('.btn-donate')||d.querySelector('.wp-block-button__link');const h=d.querySelector('header');return [Math.round(b.getBoundingClientRect().width),Math.round(h.getBoundingClientRect().height)]}))}</script>
JS;
        $file = tempnam(sys_get_temp_dir(), 'button-width-') . '.html';
        $profile = sys_get_temp_dir() . '/button-width-profile-' . getmypid();
        file_put_contents($file, $html);
        try {
            $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($profile) . ' --dump-dom --virtual-time-budget=2500 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            preg_match('/data-result="([^"]+)"/', (string) $output, $match);
            return json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true) ?? array();
        } finally {
            @unlink($file);
            shell_exec('rm -rf ' . escapeshellarg($profile));
        }
    };
    foreach ( array(390, 1023, 1024, 1440) as $width ) {
        $styles = $fingerprint($width);
        $assert(2 === count($styles), 'Source and transformed page are measured at ' . $width . 'px.');
        $assert($styles[0] === $styles[1], 'Button and header geometry matches at ' . $width . 'px: ' . json_encode($styles));
    }
}
echo "Native button responsive width override contract passed.\n";
