<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Comparison-only current-document state; never rewrites emitted URLs. */
final class RouteSelfReference
{
    /** @param list<string> $selfUrls */
    public static function value(string $value, array $selfUrls): string
    {
        if (in_array($value, $selfUrls, true)) return 'blocks-engine-self-document';
        // Only whole query parameter values qualify, not prefixes, fragments,
        // paths or a URL embedded in arbitrary authored prose.
        $query = parse_url($value, PHP_URL_QUERY);
        if (!is_string($query)) return $value;
        $parameters = explode('&', $query);
        $changed = false;
        foreach ($parameters as &$parameter) {
            $pair = explode('=', $parameter, 2);
            if (2 !== count($pair) || !in_array(urldecode($pair[1]), $selfUrls, true)) continue;
            $parameter = $pair[0] . '=blocks-engine-self-document';
            $changed = true;
        }
        unset($parameter);
        if (!$changed) return $value;
        $start = strpos($value, '?');
        if (false === $start) return $value;
        $fragment = strpos($value, '#', $start);
        return substr($value, 0, $start + 1) . implode('&', $parameters) . (false === $fragment ? '' : substr($value, $fragment));
    }

    /** @param list<string> $selfUrls */
    public static function markup(string $markup, array $selfUrls): string
    {
        $dom = HtmlSerialization::parseDocument('<body>' . $markup . '</body>');
        $walk = static function (\DOMNode $node) use (&$walk, $selfUrls): void {
            if ($node instanceof \DOMElement) {
                foreach ($node->attributes as $attribute) $attribute->value = self::value($attribute->value, $selfUrls);
            } elseif ($node instanceof \DOMText) {
                $value = trim($node->data);
                $normalized = self::value($value, $selfUrls);
                if ($normalized !== $value) $node->data = str_replace($value, $normalized, $node->data);
            } elseif ($node instanceof \DOMComment && preg_match('/^(\s*wp:[^\s]+\s+)(\{.*\})(\s*\/?\s*)$/s', $node->data, $match)) {
                $attributes = json_decode($match[2], true);
                if (is_array($attributes)) {
                    array_walk_recursive($attributes, static function (&$value) use ($selfUrls): void {
                        if (!is_string($value)) return;
                        $value = str_contains($value, '<') ? self::markup($value, $selfUrls) : self::value($value, $selfUrls);
                    });
                    $node->data = $match[1] . BlockAttributes::encode($attributes) . $match[3];
                }
            }
            foreach ($node->childNodes as $child) $walk($child);
        };
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) return $markup;
        $walk($body);
        $result = '';
        foreach ($body->childNodes as $child) $result .= $dom->saveHTML($child);
        return $result;
    }
}
