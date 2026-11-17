<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Exercise the published save function, not the transformer's PHP validity report.
$source = '<div class="carousel slideshow"><div class="carousel-inner">'
    . '<div class="carousel-item active"><img src="one.jpg" alt="One"></div>'
    . '<div class="carousel-item"><img src="two.jpg" alt="Two"></div></div>'
    . '<button class="carousel-control-prev" aria-label="Previous">Previous</button>'
    . '<button class="carousel-control-next" aria-label="Next">Next</button></div>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$block = $result['blocks'][0] ?? array();
if ('custom/authored-carousel' !== ($block['blockName'] ?? '') || 'slideshow' !== ($block['attrs']['presentation'] ?? '')) {
    throw new RuntimeException('The regression source must import as an authored slideshow.');
}
$definition = $result['source_reports']['generated_blocks'][0];
$runner = <<<'JS'
const fs = require('node:fs');
const path = require('node:path');
const React = require(path.resolve('tools/visual-parity/node_modules/react'));
const { renderToStaticMarkup } = require(path.resolve('tools/visual-parity/node_modules/react-dom/server'));
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
let settings;
function InnerBlocks() {}
InnerBlocks.Content = () => null;
global.window = { wp: {
    blocks: { registerBlockType: (name, value) => { settings = value; } },
    blockEditor: { InnerBlocks }, element: React, components: {}
} };
eval(input.editor);
const attributes = Object.fromEntries(Object.entries(settings.attributes).map(([key, schema]) => [key, schema.default]));
Object.assign(attributes, input.attributes);
process.stdout.write(renderToStaticMarkup(settings.save({ attributes })));
JS;

// Both ordinary arrows and retained source topology share the controls overlay.
foreach (array('slideshow', 'track', 'src-swap') as $presentation) {
    foreach (array('', '<section><button data-carousel-next="true">Next</button></section>') as $topology) {
        $attributes = array_replace($block['attrs'], array('presentation' => $presentation, 'sourceControlTopology' => $topology));
        $generator = new AuthoredCarouselBlockGenerator();
        $shell = $generator->shell($attributes);
        $php = $shell['opening'] . $shell['closing'];
        $process = proc_open(array('node', '-e', $runner), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot execute the carousel save regression.');
        }
        fwrite($pipes[0], json_encode(array('editor' => $definition['assets']['index.js'], 'attributes' => $attributes), JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $js = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (0 !== proc_close($process)) {
            throw new RuntimeException('Carousel save failed: ' . $error);
        }
        $controls = static function (string $html): string {
            $document = new DOMDocument();
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $node = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " blocks-engine-authored-carousel__controls ")]')->item(0);
            if (!$node instanceof DOMElement) {
                throw new RuntimeException('Missing saved controls.');
            }
            // Gutenberg ignores attribute order and surrounding class whitespace.
            foreach ($xpath->query('.//*[@class] | self::*[@class]', $node) as $element) {
                $element->setAttribute('class', trim($element->getAttribute('class')));
            }
            return $node->C14N();
        };
        if ($controls($php) !== $controls($js)) {
            throw new RuntimeException($presentation . ': PHP controls differ from the real JS save: ' . $js);
        }
    }
}
echo "OK: authored carousel controls match the published save function\n";
