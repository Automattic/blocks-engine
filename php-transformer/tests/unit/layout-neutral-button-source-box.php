<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$failures = 0;
$neutral = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS;
foreach ( array(
    'avatar label' => array('display:inline-block;position:relative;font-size:12px;line-height:28px;height:30px;width:100%;padding:0 5px', array('display' => 'inline-block', 'width' => '100%', 'height' => '30px')),
    'unstyled' => array('', array('display' => 'inline', 'width' => 'fit-content', 'height' => 'auto')),
    'flex' => array('display:flex', array('display' => 'flex', 'width' => 'fit-content', 'height' => 'auto')),
    'block' => array('display:block', array('display' => 'block', 'width' => 'fit-content', 'height' => 'auto')),
    'width only' => array('width:100%', array('display' => 'inline', 'width' => '100%', 'height' => 'auto')),
    'height only' => array('height:30px', array('display' => 'inline', 'width' => 'fit-content', 'height' => '30px')),
) as $label => [ $style, $expected ] ) {
    $html = '<html><head><style>#bar{width:150px;height:30px;overflow:hidden}#user a{' . $style . '}#user a img{vertical-align:middle;max-width:25px;max-height:25px;margin:0 3px}#bar span.label{width:calc(100% - 31px)}</style></head><body>'
        . '<header><div id="bar"><div id="user"><a id="user_link"><img id="avatar" src="a.svg" alt="avatar"><span class="label">Sign in</span></a></div></div></header>'
        . '<main><h1>Example</h1></main></body></html>';
    if ( 'flex' === $label ) {
        $html = str_replace('<a id="user_link">', '<a id="user_link" class="flex">', $html);
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
    )))->toArray();
    $output = (string) ($result['serialized_blocks'] ?? '');
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? '')), 'content'));
    if ( ! preg_match('/class="([^"]*' . $neutral . '[^\"]*)"/', $output, $match) ) {
        fwrite(STDERR, "FAIL: {$label}: missing layout-neutral button\n");
        ++$failures;
        continue;
    }
    $classes = explode(' ', $match[1]);

    // Evaluate the engine's simple modifier selectors in source order. Author
    // declarations win unless an applicable important support reset replaces them.
    $actual = array();
    foreach ( $expected as $property => $value ) {
        if ( preg_match('/(?:^|;)' . $property . ':([^;]+)/', $style, $authored) ) {
            if ( ! str_contains($css, $property . ':' . $authored[1]) ) {
                fwrite(STDERR, "FAIL: {$label}: authored {$property} missing from emitted CSS\n");
                ++$failures;
            }
            $actual[$property] = $authored[1];
        }
    }
    preg_match_all('/:where\(([^{}]+)\)>\.wp-block-button__link\{([^}]+)\}/', $css, $rules, PREG_SET_ORDER);
    foreach ( $rules as $rule ) {
        $matches = false;
        foreach ( explode(',', $rule[1]) as $selector ) {
            if ( ! str_contains($selector, '.' . $neutral) ) {
                continue;
            }
            preg_match_all('/:not\(\.([\w-]+)\)/', $selector, $excluded);
            if ( array_intersect($classes, $excluded[1]) ) {
                continue;
            }
            $selector = preg_replace('/:not\([^)]*\)/', '', $selector);
            preg_match_all('/\.([\w-]+)/', $selector, $required);
            if ( ! array_diff($required[1], $classes) ) {
                $matches = true;
            }
        }
        if ( $matches ) {
            preg_match_all('/(?:^|;)(display|width|height):([^;!]+)!important/', $rule[2], $declarations, PREG_SET_ORDER);
            foreach ( $declarations as $declaration ) {
                $actual[$declaration[1]] = $declaration[2];
            }
        }
    }
    if ( $actual != $expected ) {
        fwrite(STDERR, 'FAIL: ' . $label . ': ' . json_encode($actual) . ' expected ' . json_encode($expected) . "\n");
        ++$failures;
    }
}
if ( $failures ) {
    exit(1);
}
echo "Layout-neutral button source boxes: passed\n";
