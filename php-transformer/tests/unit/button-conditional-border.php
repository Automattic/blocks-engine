<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

// Issue #4307's reduced fixture, with variants isolating condition and border syntax.
$fixture = <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><title>Media border button</title>
<style>
.btn{display:inline-block;padding:12px 24px;background:#e4f2d4;border-radius:999px;color:#275f49;text-decoration:none}
</style>
%s
</head><body>
<p>Text.</p>
<a class="btn" href="#x">Contact</a>
</body></html>
HTML;

$cases = array(
    'desktop media' => '<style>@media (min-width:1024px){.btn{border:1px solid #275f49}}</style>',
    'mobile media' => '<style>@media (max-width:600px){.btn{border:1px solid #275f49}}</style>',
    'style media' => '<style media="(min-width:1024px)">.btn{border:1px solid #275f49}</style>',
    'logical longhands' => '<style>@media (max-width:600px){.btn{border-inline-start-width:var(--stroke);border-inline-start-style:solid;border-block-end-width:calc(1px + 1px);border-block-end-style:solid}}</style>',
    'unconditional logical' => '<style>.btn{border-inline-width:1px;border-inline-style:solid}</style>',
    'no border' => '',
);
foreach ( $cases as $label => $authorStyle ) {
    $result = (new HtmlTransformer())->transform(sprintf($fixture, $authorStyle))->toArray();
    $buttons = array();
    $walk = static function (array $blocks) use (&$walk, &$buttons): void {
        foreach ( $blocks as $block ) {
            if ( 'core/button' === ($block['blockName'] ?? '') ) {
                $buttons[] = $block;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($result['blocks']);
    $assert(1 === count($buttons), "{$label}: Contact becomes core/button");
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    $borderless = 'no border' === $label;
    foreach ( array('border-style:none!important', 'border-width:0!important') as $reset ) {
        $assert($borderless === str_contains($css, $reset), "{$label}: " . ($borderless ? 'keeps ' : 'does not emit ') . $reset);
    }
    if ( ! $borderless ) {
        preg_match('/\.btn\{([^}]+)\}/', $authorStyle, $match);
        foreach ( explode(';', $match[1]) as $declaration ) {
            $assert(str_contains($css, $declaration), "{$label}: retains author {$declaration}");
        }
        if ( str_contains($authorStyle, '600px') ) {
            $assert(str_contains($css, 'max-width:600px'), "{$label}: retains mobile condition");
        } elseif ( str_contains($authorStyle, '@media (min-width:1024px)') ) {
            $assert(str_contains($css, 'min-width:1024px'), "{$label}: retains desktop condition");
        }
    }
}
echo "PASS: conditional author button borders survive; borderless buttons retain resets\n";
