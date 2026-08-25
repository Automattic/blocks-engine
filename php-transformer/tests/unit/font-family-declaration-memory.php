<?php
declare(strict_types=1);

ini_set('memory_limit', '96M');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;

$css = '@keyframes inert{' . str_repeat('x', 32 * 1024 * 1024) . '}'
    . ':root{--heading:"Playfair Display",serif;--body:"Lora",serif}'
    . 'body{font-family:var(--body)}h1{font-family:var(--heading)}';
$roles = ( new FontMaterializationPlanBuilder() )->fontRolesFromCss($css);

if ( array( 'heading' => 'Playfair Display', 'body' => 'Lora' ) !== $roles ) {
    fwrite(STDERR, 'Font-family declaration memory contract failed: ' . json_encode($roles) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Font-family declaration memory contract passed\n");
