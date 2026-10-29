<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

// The landmark sibling makes the button a navigation-toggle candidate. The
// container must still use ordinary list lowering rather than terminal fallback.
$html = '<!doctype html><html><head><meta charset="utf-8"><title>m</title></head><body><menu><button>Menu</button><span>Home</span></menu><nav><a href="/">Home</a></nav></body></html>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$unsupported = array_filter($result['fallbacks'] ?? array(), static fn (array $row): bool => 'html_unsupported_element' === ($row['diagnostic_code'] ?? ''));
$assert(array() === $unsupported, 'menu beside navigation must not produce unsupported-element fallbacks');
$markup = $result['serialized_blocks'] ?? '';
$assert(str_contains($markup, '<!-- wp:button ') && str_contains($markup, '>Menu</button>'), 'menu button survives as a native button');
$assert(str_contains($markup, '<!-- wp:paragraph') && 1 === preg_match('~<p(?: class="[^"]*")?><span>Home</span></p>~', $markup), 'menu label survives as native text, separately from the navigation link');
$assert(str_contains($markup, '<!-- wp:navigation-link '), 'adjacent navigation remains native');

// A semantic unordered list also supports ordinary items and nested lists.
$plain = (new HtmlTransformer())->transform('<main><menu><li>One<menu><li>Two</li></menu></li></menu></main>', array())->toArray();
$markup = $plain['serialized_blocks'] ?? '';
$assert(2 === substr_count($markup, '<!-- wp:list '), 'menu items and nested menus use native lists');
$assert(str_contains($markup, 'One') && str_contains($markup, 'Two'), 'nested menu text survives');

echo "menu list container passed\n";
