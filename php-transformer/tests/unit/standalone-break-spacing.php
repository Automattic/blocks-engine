<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = 'body{font-size:18px;line-height:1.6}';
$heads = array(
    'plain' => '<style>' . $css . '</style>',
    'linked media' => '<link rel="stylesheet" href="s.css" media="(min-width:768px)"><style>@media(max-width:767px){body{line-height:9}}</style>',
    'imported ranges' => '<style>@import url("/s.css") (min-width:768px);</style><style>@import url("/s.css") (min-width:641px) and (max-width:767px);</style><style>@import url("/s.css") (max-width:640px);</style>',
    'font shorthand' => '<style>body{font:18px/1.6 serif}</style>',
    'unresolved' => '',
);
$failed = false;
foreach ( $heads as $name => $head ) {
    $html = '<!doctype html><html><head>' . $head . '</head><body><main><br><br><section><div>Title</div><br><br><div>Address</div></section><br><section><p>Form</p></section><br><br></main></body></html>';
    $result = ( new ArtifactCompiler() )->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 's.css', 'kind' => 'css', 'content' => $css),
    )))->toArray();
    $markup = '';
    array_walk_recursive($result, static function ($value, $key) use (&$markup): void {
        if ( 'block_markup' === $key && is_string($value) ) $markup .= $value;
    });
    preg_match_all('/<!-- wp:spacer \{"height":"([^"]+)"/', $markup, $matches);
    $ok = 'unresolved' === $name
        ? 7 === substr_count($markup, '<p style="margin-top:0;margin-bottom:0"><br></p>')
        : array('57.6px', '57.6px', '28.8px', '57.6px') === $matches[1];
    $ok = $ok && ! str_contains($markup, '<p></p>');
    if ( ! $ok ) {
        fwrite(STDERR, "FAIL: {$name} must preserve each standalone break's line box, including leading and trailing runs\n" . $markup . "\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
