<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Source selector identity and the passive attributes an owned DOM host may carry. */
final class SourceAttribute
{
    public const SELECTOR_NAMES = array('class', 'id', 'dir', 'lang', 'hidden');
    public const SELECTOR_PATTERN = '^(?:data|aria)-[a-z0-9_-]+$';
    public const STATIC_NAMES = array('role', 'title', 'tabindex');
    public const DECLARED_EVENTS = array('data-action', 'data-on', 'data-event', 'jsaction');

    public static function isSelectorAttribute(string $name): bool
    {
        return in_array($name, self::SELECTOR_NAMES, true)
            || 1 === preg_match('/' . self::SELECTOR_PATTERN . '/', $name);
    }

    public static function isDeclaredEventAttribute(string $name): bool
    {
        return in_array(strtolower($name), self::DECLARED_EVENTS, true);
    }

    /** One policy is consumed by PHP projection and generated editor/save code. */
    public static function staticPolicy(): array
    {
        return array(
            'names' => array_merge(self::SELECTOR_NAMES, self::STATIC_NAMES),
            'pattern' => self::SELECTOR_PATTERN,
            'excludedNames' => self::DECLARED_EVENTS,
            'excludedPrefix' => 'data-wp-',
        );
    }

    /** @param array<string, mixed> $attributes @param list<string> $ownedNames @return array<string, string> */
    public static function staticAttributes(array $attributes, array $ownedNames = array()): array
    {
        $safe = array();
        $policy = self::staticPolicy();
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !is_string($value) || in_array($name, $ownedNames, true)
                || in_array($name, $policy['excludedNames'], true) || str_starts_with($name, $policy['excludedPrefix'])) {
                continue;
            }
            if (in_array($name, $policy['names'], true) || 1 === preg_match('/' . $policy['pattern'] . '/', $name)) {
                $safe[$name] = $value;
            }
        }
        ksort($safe);
        return $safe;
    }
}
