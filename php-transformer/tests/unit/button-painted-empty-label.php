<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #5884: the exact issue fixture is the acceptance input.
$fixture = <<<'HTML'
<style>.icon{display:inline-block;width:16px;height:16px;border-radius:50%;background:#c9a227}.icon:before{content:"✦"}.btn{display:inline-flex;padding:12px 22px;border-radius:999px;background:#0b5e4b;color:#fff}</style>
<a class="btn" href="/packages/">View all packages <span><span class="icon" aria-hidden="true"></span></span></a>
<a class="text" href="/contact/">Contact the team <span class="icon" aria-hidden="true"></span></a>
HTML;
$cases = array(
    'issue fixture' => array($fixture, 1, true),
);
foreach ( array(
    'size' => 'width:16px;height:16px',
    'background' => 'background:#c9a227',
    'border' => 'border:1px solid gold',
    'before' => '',
    'after' => '',
    'unpainted' => '',
) as $name => $style ) {
    $pseudo = in_array($name, array('before', 'after'), true) ? '.icon::' . $name . '{content:"✦"}' : '';
    $cases[$name] = array(
        '<style>.btn{display:inline-flex;padding:12px;background:green}.icon{' . $style . '}' . $pseudo . '</style>'
        . '<a class="btn" href="/packages/">Label <span aria-hidden="true"><span class="icon" aria-hidden="true"></span></span></a>',
        'unpainted' === $name ? 0 : 1,
        false,
    );
}

$failures = array();
foreach ( $cases as $name => list($html, $expected, $issue) ) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
    $markup = $result['serialized_blocks'];
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $markup, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $icons = $xpath->query('//a[contains(@class,"wp-block-button__link")]//*[contains(concat(" ",normalize-space(@class)," ")," icon ")][@aria-hidden="true"]');
    if ( $expected !== $icons->length ) {
        $failures[] = $name . ': expected painted icons with class and aria-hidden inside the core/button label; got ' . $markup;
    }
    if ( $expected > 0 ) {
        $nested = $xpath->query('//a[contains(@class,"wp-block-button__link")]/span/span[contains(concat(" ",normalize-space(@class)," ")," icon ")]');
        if ( 1 !== $nested->length ) {
            $failures[] = $name . ': wrapper around the painted icon must survive';
        }
    }
    if ( $issue ) {
        foreach ( array('/packages/' => 'View all packages', '/contact/' => 'Contact the team') as $href => $label ) {
            $anchor = $xpath->query('//a[@href="' . $href . '"]')->item(0);
            if ( !$anchor instanceof DOMElement || $label !== trim($anchor->textContent) ) {
                $failures[] = $name . ': link destination and visible label must survive';
            }
        }
        // Current lowering keeps the undecorated contact link inline. Its badge
        // must survive too; the painted packages link exercises core/button.
        if ( 1 !== $xpath->query('//a[@href="/contact/"]/span[@class="icon"][@aria-hidden="true"]')->length ) {
            $failures[] = $name . ': inline contact badge must survive';
        }
        $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
        if ( !str_contains($css, 'content:') || !str_contains($css, '#c9a227') ) {
            $failures[] = $name . ': badge paint and generated content must remain in emitted CSS';
        }
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Painted empty button labels passed\n";
