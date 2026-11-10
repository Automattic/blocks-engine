<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LiveClockBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\MotionSequenceBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use DOMElement;

/** Lowers independently authored portable runtime markers to editable blocks. */
final class AuthoredMotionMarkerConverter implements ElementConverter
{
    public function __construct(private readonly HtmlTransformerSession $session, private readonly SourceBlockCreator $createBlock)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $block = $this->markerBlock($element);

        return null === $block ? ConversionOutcome::unhandled() : ConversionOutcome::handled($block);
    }

    /** @return array<string, mixed>|null */
    private function markerBlock(DOMElement $element): ?array
    {
        if ('span' !== strtolower($element->tagName) || !$element->hasAttribute('hidden') || '' !== trim($element->textContent ?? '')) return null;
        foreach (array(
            'data-blocks-engine-motion-steps' => array(MotionSequenceBlockGenerator::class, 'steps', 'array'),
            'data-blocks-engine-live-clock' => array(LiveClockBlockGenerator::class, 'config', 'object'),
        ) as $attribute => $spec) {
            if (!$element->hasAttribute($attribute)) continue;
            $raw = $element->getAttribute($attribute);
            if (strlen($raw) > 8192) return null;
            $value = json_decode($raw, true);
            if (!is_array($value) || ('array' === $spec[2] && (!array_is_list($value) || count($value) > 8)) || ('object' === $spec[2] && array_is_list($value))) return null;
            $registry = $this->session->generatedBlockRegistry();
            if (null === $registry) throw new \LogicException('Generated block registry has not been prepared.');
            $generator = new $spec[0]();
            $name = $generator::LOCAL_NAME;
            $registry->register($spec[0], $generator->definition($registry->blockName($name)));
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $block = $this->createBlock->createBlock($registry->blockName($name), array($spec[1] => $value), array(), $element);
            $block['innerHTML'] = '<span hidden ' . $attribute . '="' . htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></span>';
            $block['innerContent'] = array($block['innerHTML']);
            return $block;
        }
        return null;
    }
}
