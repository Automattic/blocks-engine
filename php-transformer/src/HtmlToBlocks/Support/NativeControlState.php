<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;

/** Adapts the known producer's inert facts to the authored-control model. */
final class NativeControlState
{
    public const ATTRIBUTE = 'data-dla-native-control-state';

    public static function isReplayScript(string $tag): bool
    {
        return array_key_exists('data-dla-native-control-runtime', HtmlTagScanner::attributes($tag));
    }

    /** Discard the producer interpreter, never grant a script trust by its marker.
     * Source bytes/identities remain owned by ingestion; this is a rendering view.
     */
    public static function withoutReplayScript(string $html): string
    {
        foreach (array_reverse(HtmlTagScanner::scan($html, 'script')) as $script) if (self::isReplayScript($script['tag'])) {
            $html = substr_replace($html, '', $script['offset'], $script['end_offset'] - $script['offset']);
        }
        return $html;
    }

    /** Validated producer facts for consumers that receive control metadata, such as form providers.
     * @return array<string,mixed>|null */
    public static function payload(DOMElement $control): ?array
    {
        return null === self::attributes($control) ? null : json_decode($control->getAttribute(self::ATTRIBUTE), true);
    }

    /** @return array<string,mixed>|null */
    public static function attributes(DOMElement $control): ?array
    {
        $state = json_decode($control->getAttribute(self::ATTRIBUTE), true);
        if (!is_array($state) || 1 !== ($state['version'] ?? null)) return null;
        $tag = strtolower($control->tagName);
        $kind = $state['kind'] ?? null;
        if ('input' === $tag) {
            $type = self::inputType($control->getAttribute('type'));
            if ('file' === $kind && 'file' === $type && self::keys($state, array('version', 'kind'))) return array('initialValue' => '');
            if ('input' !== $kind || 'file' === $type || $type !== ($state['type'] ?? null)
                || !self::keys($state, array('version', 'kind', 'type', 'value', 'defaultValue', 'checked', 'defaultChecked', 'indeterminate'))
                || !is_string($state['value']) || !is_string($state['defaultValue']) || !is_bool($state['checked'])
                || !is_bool($state['defaultChecked']) || !is_bool($state['indeterminate'])) return null;
            $attributes = array('value' => $state['defaultValue'], 'checked' => $state['defaultChecked'],
                'valueDeclared' => $control->hasAttribute('value'), 'indeterminate' => $state['indeterminate']);
            // Current state the native markup already reproduces needs no payload.
            if ($state['value'] === $state['defaultValue'] && $state['checked'] === $state['defaultChecked'] && !$state['indeterminate']) return $attributes;
            return $attributes + array('initialValue' => $state['value'], 'initialChecked' => $state['checked']);
        }
        if ('textarea' === $tag && 'textarea' === $kind && self::keys($state, array('version', 'kind', 'value', 'defaultValue'))
            && is_string($state['value']) && is_string($state['defaultValue'])) {
            return $state['value'] === $state['defaultValue'] ? array('value' => $state['defaultValue']) : array('value' => $state['defaultValue'], 'initialValue' => $state['value']);
        }
        if ('select' !== $tag || 'select' !== $kind || !self::keys($state, array('version', 'kind', 'options'))
            || !is_array($state['options']) || !array_is_list($state['options'])
            || count($state['options']) !== $control->getElementsByTagName('option')->length) return null;
        $selected = 0;
        foreach ($state['options'] as $option) {
            if (!is_array($option) || !self::keys($option, array('selected', 'defaultSelected'))
                || !is_bool($option['selected']) || !is_bool($option['defaultSelected'])) return null;
            $selected += (int) $option['selected'];
        }
        if (!$control->hasAttribute('multiple') && $selected > 1) return null;
        $options = array(); $groups = array();
        foreach ($control->getElementsByTagName('option') as $index => $option) {
            $item = array('label' => $option->textContent, 'value' => $option->getAttribute('value'),
                'valueDeclared' => $option->hasAttribute('value'), 'disabled' => $option->hasAttribute('disabled'),
                'selected' => $state['options'][$index]['defaultSelected'], 'initialSelected' => $state['options'][$index]['selected']);
            $parent = $option->parentNode;
            if ($parent instanceof DOMElement && 'optgroup' === strtolower($parent->tagName)) {
                $key = $parent->getNodePath();
                if (!isset($groups[$key])) $groups[$key] = count($groups) + 1;
                $item['group'] = array('index' => $groups[$key], 'label' => $parent->getAttribute('label'), 'disabled' => $parent->hasAttribute('disabled'));
            }
            $options[] = $item;
        }
        return array('options' => $options, 'multiple' => $control->hasAttribute('multiple'));
    }

    public static function inputType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, array('button', 'checkbox', 'color', 'date', 'datetime-local', 'email', 'file', 'hidden', 'image', 'month', 'number', 'password', 'radio', 'range', 'reset', 'search', 'submit', 'tel', 'text', 'time', 'url', 'week'), true) ? $type : 'text';
    }

    /** @param array<string,mixed> $state @param array<int,string> $keys */
    private static function keys(array $state, array $keys): bool
    {
        return count($state) === count($keys) && array() === array_diff($keys, array_keys($state));
    }
}
