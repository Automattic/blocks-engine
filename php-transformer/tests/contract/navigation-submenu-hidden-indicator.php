<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// A hover-opened submenu parent (its link leads to a page and announces the
// popup) whose only chevron lives in a keyboard toggle beside the link.
// Visible, the toggle is the source's indicator and core keeps its own.
// Clipped away, it is a focus-only affordance: the source paints no
// indicator, so core must not draw one under the label.
$page = static fn (string $toggle): string => '<!doctype html><html><head><style>'
    . '.menu{display:flex;gap:24px;list-style:none;margin:0;padding:0}.menu>li{display:list-item;position:relative}'
    . '.row{display:flex;align-items:center}.icon-wrap{width:0}.toggle{' . $toggle . '}'
    . '.submenu{display:none;position:absolute;top:100%;left:0}.menu>li:hover .submenu{display:block}'
    . '</style></head><body><header><nav><ul class="menu">'
    . '<li><a href="/">Home</a></li>'
    . '<li><div class="row"><a href="/connect/" aria-haspopup="true" aria-expanded="false">Connect</a><div class="icon-wrap"><button class="toggle" aria-label="More Connect pages">'
    . '<svg width="10" height="6" viewBox="0 0 10 6"><path d="M0 0l5 6 5-6z"/></svg></button></div></div>'
    . '<ul class="submenu"><li><a href="/connect/contact/">Contact</a></li></ul></li>'
    . '<li><a href="/about/">About</a></li>'
    . '</ul></nav></header><main><p>Body.</p></main></body></html>';

foreach (array('visible' => 'padding:0', 'clip-path' => 'clip:rect(0 0 0 0);clip-path:inset(50%);width:0;height:0', 'clip' => 'position:absolute;clip:rect(0 0 0 0);width:1px;height:1px') as $case => $toggle) {
    $markup = (string) ((new HtmlTransformer())->transform($page($toggle))->toArray()['serialized_blocks'] ?? '');
    $attrs = static fn (string $block): array => 1 === preg_match('/<!-- wp:' . $block . ' (\{.*?\}) -->/s', $markup, $match) ? (json_decode($match[1], true) ?: array()) : array();
    $assert('Connect' === ($attrs('navigation-submenu')['label'] ?? null), "{$case}: Connect lowers to a native submenu.");
    $showsIcon = false !== ($attrs('navigation')['showSubmenuIcon'] ?? true);
    $assert(('visible' === $case) === $showsIcon, "{$case}: core paints a submenu icon only when the source toggle is visible.");
}
