<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// core/table saves its figcaption after the table. A source caption that
// renders on top (the `caption-side` default) must still display above the
// table, scoped to that block's generated table class.
$assert = static function (bool $ok, string $message): void {
    if ( ! $ok ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$compile = static function (string $css, string $captionAttributes = ''): array {
    $html = '<!DOCTYPE html><html><head><title>Table</title><style>.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}' . $css . '</style></head><body><main><h1>Compare</h1><table class="compare"><caption' . $captionAttributes . '>Comparison of homes</caption><thead><tr><th>Feature</th><th>A</th></tr></thead><tbody><tr><td>Beds</td><td>3</td></tr></tbody></table></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray();
    $markup = (string) $result['serialized_blocks'];
    $css = '';
    foreach ( $result['assets'] as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' ) . "\n";
        }
    }
    preg_match('/(?:^|\s)(blocks-engine-table-[a-f0-9]{12}-\d+)(?:\s|")/', $markup, $match);
    return array( 'markup' => $markup, 'css' => $css, 'marker' => $match[1] ?? '' );
};
$placesCaptionAbove = static function (array $output): bool {
    $marker = preg_quote($output['marker'], '/');
    return '' !== $output['marker']
        && 1 === preg_match('/\.' . $marker . '\{display:flex;flex-direction:column\}/', $output['css'])
        && 1 === preg_match('/\.' . $marker . '>figcaption\{order:-1\}/', $output['css']);
};

$cases = array(
    'default caption-side' => array( '', '', true ),
    'caption-side:top on caption' => array( 'caption{caption-side:top}', '', true ),
    'caption-side:bottom on caption' => array( 'caption{caption-side:bottom}', '', false ),
    'caption-side:bottom inherited from table' => array( '.compare{caption-side:bottom}', '', false ),
    'inline caption-side:bottom' => array( '', ' style="caption-side:bottom"', false ),
    'visually hidden caption' => array( '', ' class="sr-only"', false ),
);
foreach ( $cases as $name => [$css, $attributes, $above] ) {
    $output = $compile($css, $attributes);
    $assert(str_contains($output['markup'], '<!-- wp:table'), $name . ': stays a native core/table');
    $assert('' !== $output['marker'], $name . ': table carries its generated class');
    $html = (string) preg_replace('/<!--.*?-->/s', '', $output['markup']);
    $assert(1 === preg_match('/<\/table><figcaption class="wp-element-caption">(?:<span class="screen-reader-text">)?Comparison of homes/', $html), $name . ': figcaption keeps core/table saved placement');
    $assert($above === $placesCaptionAbove($output), $name . ': caption placement rule ' . ( $above ? 'emitted' : 'absent' ));
    $assert(! str_contains($output['markup'], 'caption-side'), $name . ': caption-side is not projected into block markup');
    $assert('pass' === ( new Runtime() )->validateBlockSerialization($output['markup'])['status'], $name . ': table is editor-valid');
}

fwrite(STDOUT, "Table caption top placement tests passed.\n");
