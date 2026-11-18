<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
};

/**
 * Compiles a page and returns its published DOM (runtime attributes restored on
 * block roots, as the render filter does) and its engine support rules.
 *
 * @return array{0: DOMDocument, 1: list<array{selector: string, declarations: string}>}
 */
$compile = static function (string $body): array {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Fixture</title></head><body>' . $body . '</body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'website/index.html',
        'files' => array( 'website/index.html' => $html ),
    ))->toArray();
    $markup = preg_replace_callback(
        '/<!-- wp:[a-z\/-]+ (\{.*?\}) -->\s*<([a-z0-9]+)\b/s',
        static function (array $match): string {
            $attrs = json_decode($match[1], true);
            $hidden = is_array($attrs) && array_key_exists('hidden', $attrs['metadata']['blocksEngineRuntimeAttributes'] ?? array());
            return $hidden ? substr($match[0], 0, -strlen($match[2]) - 1) . '<' . $match[2] . ' hidden' : $match[0];
        },
        $result['serialized_blocks']
    );
    $document = new DOMDocument();
    @$document->loadHTML('<!doctype html><html><body>' . $markup . '</body></html>');
    $rules = array();
    foreach ( $result['assets'] as $asset ) {
        if ( 'engine-support' !== ($asset['source'] ?? '') ) continue;
        preg_match_all('/([^{}@]+)\{([^{}]*)\}/', (string) ($asset['content'] ?? ''), $matches, PREG_SET_ORDER);
        foreach ( $matches as $match ) {
            foreach ( explode(',', $match[1]) as $selector ) {
                $rules[] = array( 'selector' => trim($selector), 'declarations' => $match[2] );
            }
        }
    }
    return array( $document, $rules );
};

/** Engine rules that give the element a display box, overriding UA `[hidden]`. */
$boxRules = static function (DOMElement $element, array $rules): array {
    $matched = array();
    foreach ( $rules as $rule ) {
        if ( 1 !== preg_match('/(?:^|;)\s*display\s*:\s*(?!none\b)[a-z-]+/i', $rule['declarations']) ) continue;
        if ( str_contains($rule['selector'], '.editor-styles-wrapper') ) continue;
        $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($rule['selector']));
        if ( $match['matches'] ) $matched[] = $rule['selector'];
    }
    return $matched;
};

$figures = static function (DOMDocument $document): array {
    $found = array();
    foreach ( $document->getElementsByTagName('figure') as $figure ) {
        $found[] = $figure;
    }
    return $found;
};

// Reduced lvluprx.com fixture: body-level images kept as a script data store.
list($document, $rules) = $compile(
    '<main><h1>Catalog</h1><p>Visible copy.</p></main>'
    . '<img id="store-a" src="https://example.com/a.jpg" hidden><img id="store-b" src="https://example.com/b.jpg" hidden>'
    . '<footer><p>Footer</p></footer>'
);
$hiddenFigures = array_values(array_filter($figures($document), static fn (DOMElement $figure): bool => $figure->hasAttribute('hidden')));
$assert(2 === count($hiddenFigures), 'both hidden source images keep the hidden attribute on their published roots');
foreach ( $hiddenFigures as $figure ) {
    $assert(array() === $boxRules($figure, $rules), 'engine CSS must not give hidden image #' . $figure->getAttribute('id') . ' a box: ' . implode(' | ', $boxRules($figure, $rules)));
}
$xpath = new DOMXPath($document);
$assert(1 === $xpath->query('//p[normalize-space(.)="Visible copy."]')->length && 1 === $xpath->query('//p[normalize-space(.)="Footer"]')->length, 'visible copy survives');

// Images that are flex items or inline content get box-less figures too. A
// hidden image beside text must not become a visible media-text pane.
foreach ( array(
    'flex item' => array( '<main><p>x</p><div style="display:flex"><img src="https://example.com/c.jpg" alt="c" hidden><p>y</p></div></main>', 'blocks-engine-synthetic-flex-image-figure' ),
    'linked image' => array( '<main><p>x</p><a href="/c"><img src="https://example.com/c.jpg" alt="c" hidden></a></main>', 'blocks-engine-synthetic-image-figure-inline' ),
    'nested image' => array( '<main><p>x</p><div><img src="https://example.com/c.jpg" alt="c" hidden></div></main>', 'blocks-engine-synthetic-image-figure-strut' ),
) as $name => list($body, $carrier) ) {
    list($document, $rules) = $compile($body);
    $hidden = array_values(array_filter($figures($document), static fn (DOMElement $figure): bool => $figure->hasAttribute('hidden')));
    $assert(1 === count($hidden) && str_contains($hidden[0]->getAttribute('class'), $carrier), $name . ': the hidden image keeps its hidden attribute on a ' . $carrier . ' root');
    $assert(0 === (new DOMXPath($document))->query('//*[contains(@class,"wp-block-media-text")]')->length, $name . ': the hidden image is not lifted into a media-text pane');
    foreach ( $hidden as $figure ) {
        $assert(array() === $boxRules($figure, $rules), $name . ': engine CSS must not give the hidden image a box: ' . implode(' | ', $boxRules($figure, $rules)));
    }
}

// A visible source image keeps its box-less carrier; nothing new hides it.
list($document, $rules) = $compile('<main><h1>Catalog</h1></main><img id="shown" src="https://example.com/a.jpg"><footer><p>Footer</p></footer>');
$shown = $document->getElementById('shown');
$assert($shown instanceof DOMElement && ! $shown->hasAttribute('hidden'), 'a visible image is not marked hidden');
$assert(array() !== $boxRules($shown, $rules), 'a visible image keeps its engine display rule');

echo "hidden-attribute-elements: ok\n";
