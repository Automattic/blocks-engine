<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use InvalidArgumentException;

/** Canonical, bounded source evidence for one observed theme preference control. */
final class ThemePreferenceOwnership
{
    public const DECLARATION_KIND = 'theme_control';
    public const DECLARATION_TYPE = 'preference_ownership';
    public const DECLARATION_SCHEMA = 'blocks-engine/php-transformer/theme-preference-ownership-evidence/v1';
    public const CONTRACT_SCHEMA = 'blocks-engine/php-transformer/theme-preference-ownership/v1';

    /** @return array<string,mixed> */
    public static function normalizePayload(mixed $payload, string $sourcePath): array
    {
        if (!is_array($payload) || self::DECLARATION_SCHEMA !== ($payload['schema'] ?? null) || !is_array($payload['ownership'] ?? null)) {
            throw new InvalidArgumentException('Theme preference declaration has an unsupported evidence schema.');
        }
        $ownership = $payload['ownership'];
        $groupSelector = $ownership['group_selector'] ?? null;
        $parsedSelector = is_string($groupSelector) && strlen($groupSelector) <= 512 && !str_contains($groupSelector, ',')
            ? CssSelectorMatcher::parse($groupSelector)
            : array('supported' => false);
        if (self::CONTRACT_SCHEMA !== ($ownership['schema'] ?? null)
            || ($ownership['source_path'] ?? null) !== $sourcePath
            || !is_string($groupSelector) || !($parsedSelector['supported'] ?? false)
            || null !== ($parsedSelector['pseudo_state_suffix_span'] ?? null)
            || !is_string($ownership['runtime_script_path'] ?? null)
            || '' === ArtifactPath::safeRelativePath($ownership['runtime_script_path'])
            || ArtifactPath::safeRelativePath($ownership['runtime_script_path']) !== $ownership['runtime_script_path']
            || !is_string($ownership['runtime_script_sha256'] ?? null)
            || 1 !== preg_match('/^[a-f0-9]{64}$/', $ownership['runtime_script_sha256'])
            || !is_string($ownership['runtime_script_content'] ?? null)
            || '' === $ownership['runtime_script_content']
            || strlen($ownership['runtime_script_content']) > 1048576
            || hash('sha256', $ownership['runtime_script_content']) !== $ownership['runtime_script_sha256']
            || !is_string($ownership['storage_key'] ?? null)
            || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $ownership['storage_key'])
            || '(prefers-color-scheme: dark)' !== ($ownership['system_query'] ?? null)) {
            throw new InvalidArgumentException('Theme preference declaration has an invalid source, anchor, asset, storage key, or system query.');
        }

        $root = $ownership['root'] ?? null;
        if (!is_array($root)
            || !in_array($root['selector'] ?? null, array('html', ':root'), true)
            || !is_string($root['attribute'] ?? null)
            || 1 !== preg_match('/^(?:class|[a-zA-Z_:][a-zA-Z0-9:._-]{0,63})$/', $root['attribute'])
            || !is_string($root['dark_value'] ?? null) || '' === $root['dark_value'] || strlen($root['dark_value']) > 128
            || !is_string($root['light_value'] ?? null) || strlen($root['light_value']) > 128) {
            throw new InvalidArgumentException('Theme preference declaration has an invalid root contract.');
        }
        $expectedOperation = 'class' === $root['attribute']
            ? ('' === $root['light_value'] ? 'remove-theme-class' : 'set-theme-class')
            : ('' === $root['light_value'] ? 'remove-attribute' : 'set-attribute');
        if ($expectedOperation !== ($root['light_operation'] ?? null)) {
            throw new InvalidArgumentException('Theme preference declaration has a contradictory light root operation.');
        }
        if (!in_array($ownership['default_preference'] ?? null, array('light', 'system', 'dark'), true)
            || !is_array($ownership['default_observations'] ?? null)
            || !array_is_list($ownership['default_observations']) || 2 !== count($ownership['default_observations'])) {
            throw new InvalidArgumentException('Theme preference declaration requires a captured unset-storage default across both OS schemes.');
        }
        $defaultSchemes = array();
        foreach ($ownership['default_observations'] as $observation) {
            if (!is_array($observation) || !array_key_exists('storage_value', $observation) || null !== $observation['storage_value']
                || !in_array($observation['os_scheme'] ?? null, array('light', 'dark'), true)
                || !in_array($observation['resolved'] ?? null, array('light', 'dark'), true)
                || !is_array($observation['root_state'] ?? null)
                || ($observation['root_state']['attribute'] ?? null) !== $root['attribute']) {
                throw new InvalidArgumentException('Theme preference default observation is missing unset storage or root state.');
            }
            $scheme = $observation['os_scheme'];
            $expectedResolved = 'system' === $ownership['default_preference'] ? $scheme : $ownership['default_preference'];
            if ($observation['resolved'] !== $expectedResolved) {
                throw new InvalidArgumentException('Theme preference default observation contradicts the captured default preference.');
            }
            $expectedRootValue = 'dark' === $expectedResolved
                ? $root['dark_value']
                : (in_array($root['light_operation'], array('remove-theme-class', 'remove-attribute'), true) ? null : $root['light_value']);
            if ($observation['root_state']['value'] !== $expectedRootValue || isset($defaultSchemes[$scheme])) {
                throw new InvalidArgumentException('Theme preference default observation contradicts its root operation or duplicates an OS scheme.');
            }
            $defaultSchemes[$scheme] = true;
        }

        $stylesheetEvidence = $ownership['stylesheet_evidence'] ?? null;
        if (!is_array($stylesheetEvidence) || !array_is_list($stylesheetEvidence) || array() === $stylesheetEvidence || count($stylesheetEvidence) > 16) {
            throw new InvalidArgumentException('Theme preference declaration requires bounded source stylesheet evidence.');
        }
        $stylesheetPaths = array();
        $stylesheetBytes = 0;
        foreach ($stylesheetEvidence as $stylesheet) {
            if (!is_array($stylesheet) || !is_string($stylesheet['path'] ?? null)
                || '' === ArtifactPath::safeRelativePath($stylesheet['path'])
                || ArtifactPath::safeRelativePath($stylesheet['path']) !== $stylesheet['path']
                || !is_string($stylesheet['sha256'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/', $stylesheet['sha256'])
                || !is_string($stylesheet['content'] ?? null) || '' === $stylesheet['content']
                || hash('sha256', $stylesheet['content']) !== $stylesheet['sha256']
                || isset($stylesheetPaths[$stylesheet['path']])) {
                throw new InvalidArgumentException('Theme preference source stylesheet evidence is malformed or stale.');
            }
            $stylesheetBytes += strlen($stylesheet['content']);
            if ($stylesheetBytes > 4194304) throw new InvalidArgumentException('Theme preference source stylesheet evidence exceeds its byte budget.');
            $stylesheetPaths[$stylesheet['path']] = true;
        }

        $controls = $ownership['controls'] ?? null;
        if (!is_array($controls) || !array_is_list($controls) || 3 !== count($controls)) {
            throw new InvalidArgumentException('Theme preference declaration requires exactly three observed controls.');
        }
        $expectedModes = array('light', 'system', 'dark');
        $expectedIcons = array('sun', 'monitor', 'moon');
        foreach ($controls as $index => $control) {
            if (!is_array($control) || ($control['mode'] ?? null) !== $expectedModes[$index]
                || !is_string($control['accessible_name'] ?? null) || '' === trim($control['accessible_name']) || strlen($control['accessible_name']) > 256
                || ($control['icon'] ?? null) !== $expectedIcons[$index]) {
                throw new InvalidArgumentException('Theme preference declaration control evidence is malformed or out of order.');
            }
        }

        $transitions = $ownership['observed_transitions'] ?? null;
        if (!is_array($transitions) || !array_is_list($transitions) || 4 !== count($transitions)) {
            throw new InvalidArgumentException('Theme preference declaration requires four browser-observed transitions.');
        }
        $seen = array();
        foreach ($transitions as $transition) {
            if (!is_array($transition) || !is_string($transition['mode'] ?? null)
                || !is_string($transition['storage_value'] ?? null) || $transition['mode'] !== $transition['storage_value']
                || !is_string($transition['resolved'] ?? null) || !is_array($transition['root_state'] ?? null)
                || ($transition['root_state']['attribute'] ?? null) !== $root['attribute']
                || !array_key_exists('value', $transition['root_state'])) {
                throw new InvalidArgumentException('Theme preference declaration transition is missing its observed storage or root state.');
            }
            $mode = $transition['mode'];
            if ('system' === $mode) {
                $scheme = $transition['os_scheme'] ?? null;
                if (!in_array($scheme, array('light', 'dark'), true) || $transition['resolved'] !== $scheme) {
                    throw new InvalidArgumentException('Theme preference declaration system transition disagrees with the observed OS scheme.');
                }
                $key = 'system:' . $scheme;
            } else {
                if (!in_array($mode, array('light', 'dark'), true) || array_key_exists('os_scheme', $transition) || $transition['resolved'] !== $mode) {
                    throw new InvalidArgumentException('Theme preference declaration explicit transition is malformed.');
                }
                $key = $mode;
            }
            $expectedRootValue = 'dark' === $transition['resolved']
                ? $root['dark_value']
                : ('remove-theme-class' === $root['light_operation'] || 'remove-attribute' === $root['light_operation'] ? null : $root['light_value']);
            if (($transition['root_state']['value'] ?? null) !== $expectedRootValue || isset($seen[$key])) {
                throw new InvalidArgumentException('Theme preference declaration observed root state contradicts its operation or duplicates a transition.');
            }
            $seen[$key] = true;
        }
        foreach (array('light', 'dark', 'system:light', 'system:dark') as $key) if (!isset($seen[$key])) {
            throw new InvalidArgumentException('Theme preference declaration omits a required explicit or system transition.');
        }

        return array('schema' => self::DECLARATION_SCHEMA, 'ownership' => RuntimeDeclarations::canonical($ownership));
    }
}
