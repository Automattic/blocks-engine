<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$css = static function (string $toggleRules, string $panelRules = 'background:#061b38', string $inline = '', string $later = ''): string {
    $styles = '.bar{display:flex;position:relative}.bar nav{display:flex;gap:1rem}.bar button{display:none}'
        . '@media(max-width:1000px){.bar button{display:flex;position:absolute;right:0;top:50%;transform:translateY(-50%);' . $toggleRules . '}'
        . '.bar nav{display:none;' . $panelRules . '}' . $later . '}';
    $html = '<style>' . $styles . '</style><header><div class="bar"><a href="/">Brand</a>'
        . '<button aria-label="Menu" aria-controls="menu" aria-expanded="false" style="' . $inline . '"><svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>'
        . '<nav id="menu"><a href="/alpha">Alpha</a><a href="/beta">Beta</a></nav></div></header><main><h1>Page</h1></main>';
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    return implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
};
$open = static function (string $css): string {
    preg_match_all('/responsive-container-open\{([^}]*)\}/', $css, $matches);
    return implode(';', $matches[1] ?? array());
};
foreach (array('stylesheet' => $css('margin-left:4px;margin:0'), 'inline' => $css('', 'background:#061b38', 'margin-left:4px;margin:0')) as $kind => $generated) {
    $body = $open($generated);
    $assert(false !== strpos($body, 'margin-left:4px!important') && false !== strpos($body, 'margin:0!important') && strpos($body, 'margin-left:4px!important') < strpos($body, 'margin:0!important'), $kind . ' shorthand after longhand retains authored order');
}
$transparent = $css('', 'background:#061b38;background-color:transparent');
$assert(!preg_match('/responsive-container\.is-menu-open:not\(\.disable-default-overlay\)\{[^}]*background:#061b38/', $transparent), 'later transparent longhand supersedes the background shorthand');
$specificBody = $open($css('', 'background:#061b38', '', 'button{top:10px}'));
$assert(str_contains($specificBody, 'top:50%!important') && !str_contains($specificBody, 'top:10px!important'), 'higher-specificity earlier source placement wins');
$importantBody = $open($css('', 'background:#061b38', '', 'button{top:10px!important}'));
$assert(str_contains($importantBody, 'top:10px!important'), 'important source placement wins over normal specificity');
if ($failures) exit(1);
echo "navigation-collapsed-cascade: 5 assertions passed\n";
