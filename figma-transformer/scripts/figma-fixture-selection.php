<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $inspection
 * @return array<int, string>
 */
function matrix_select_frame_ids(array $inspection, int $maxPages): array
{
    $candidates = is_array($inspection['candidates'] ?? null) ? $inspection['candidates'] : array();

    // Exclude design-system frames (style guides, token sheets, component
    // libraries) before any other filtering. They inform the generated
    // design system but are never page candidates.
    $candidates = array_values(array_filter(
        $candidates,
        static fn (mixed $candidate): bool => is_array($candidate) && 'design_system' !== matrix_candidate_role($candidate)
    ));

    $pageLikeCandidates = array_values(array_filter(
        $candidates,
        static fn (mixed $candidate): bool => is_array($candidate) && matrix_is_page_like_candidate($candidate)
    ));

    // Figma Dev Mode status (#280) is the PRIMARY signal only when it marks a
    // real page-like candidate. Otherwise fall back to heuristic page selection,
    // matching ScenegraphPagePlanner and avoiding dev-marked title/divider cards.
    $devMarkedPageLikeCandidates = array_values(array_filter(
        $pageLikeCandidates,
        static fn (mixed $candidate): bool => is_array($candidate) && null !== matrix_candidate_dev_status($candidate)
    ));
    if ( ! empty($devMarkedPageLikeCandidates) ) {
        $candidates = $devMarkedPageLikeCandidates;
    }

    usort($candidates, static fn (mixed $a, mixed $b): int => matrix_candidate_rank(is_array($b) ? $b : array()) <=> matrix_candidate_rank(is_array($a) ? $a : array()));

    $selected = array();
    $selectedBuckets = array();
    $deferred = array();
    foreach ( $candidates as $candidate ) {
        if ( count($selected) >= $maxPages || ! is_array($candidate) ) {
            break;
        }

        $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
        if ( '' === $id || ! matrix_is_page_like_candidate($candidate) ) {
            continue;
        }

        $bucket = matrix_candidate_bucket($candidate);
        if ( isset($selectedBuckets[$bucket]) ) {
            $deferred[] = $candidate;
            continue;
        }

        $selected[] = $id;
        $selectedBuckets[$bucket] = true;
    }

    foreach ( $deferred as $candidate ) {
        if ( count($selected) >= $maxPages || ! is_array($candidate) ) {
            break;
        }

        $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
        if ( '' !== $id && ! in_array($id, $selected, true) ) {
            $selected[] = $id;
        }
    }

    if ( empty($selected) ) {
        $fallbackCandidates = ! empty($pageLikeCandidates) ? $pageLikeCandidates : $candidates;
        foreach ( $fallbackCandidates as $candidate ) {
            if ( count($selected) >= $maxPages || ! is_array($candidate) ) {
                break;
            }

            $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
            if ( '' !== $id ) {
                $selected[] = $id;
            }
        }
    }

    return matrix_order_selected_frame_ids($selected, $candidates);
}

/**
 * @param array<int, string> $selected
 * @param array<int, mixed>  $candidates
 * @return array<int, string>
 */
function matrix_order_selected_frame_ids(array $selected, array $candidates): array
{
    $byId = array();
    $positions = array();
    foreach ( $selected as $index => $id ) {
        $positions[$id] = $index;
    }

    foreach ( $candidates as $candidate ) {
        if ( is_array($candidate) && isset($candidate['id']) && is_scalar($candidate['id']) ) {
            $byId[(string) $candidate['id']] = $candidate;
        }
    }

    usort(
        $selected,
        static function (string $left, string $right) use ($byId, $positions): int {
            $leftBucket = isset($byId[$left]) ? matrix_candidate_bucket($byId[$left]) : '';
            $rightBucket = isset($byId[$right]) ? matrix_candidate_bucket($byId[$right]) : '';
            if ( 'homepage' === $leftBucket || 'homepage' === $rightBucket ) {
                return ('homepage' === $leftBucket ? 0 : 1) <=> ('homepage' === $rightBucket ? 0 : 1);
            }

            return ((int) ($positions[$left] ?? 0)) <=> ((int) ($positions[$right] ?? 0));
        }
    );

    return $selected;
}

/**
 * Determine which signal drives matrix selection: 'dev_status' when any
 * candidate carries a normalized Figma dev status, otherwise 'heuristic'.
 *
 * @param array<int, mixed> $candidates
 */
function matrix_selection_source(array $candidates): string
{
    foreach ( $candidates as $candidate ) {
        if ( is_array($candidate) && null !== matrix_candidate_dev_status($candidate) ) {
            return 'dev_status';
        }
    }

    return 'heuristic';
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_candidate_dev_status(array $candidate): ?string
{
    $status = $candidate['dev_status'] ?? null;
    if ( in_array($status, array('ready_for_dev', 'completed'), true) ) {
        return (string) $status;
    }

    return null;
}

/**
 * Return the candidate's classifier role (design_system|page), or null when
 * no role is present (e.g., candidates from an older inspection without role
 * classification). The caller is responsible for the null-is-unknown fallback.
 *
 * @param array<string, mixed> $candidate
 */
function matrix_candidate_role(array $candidate): ?string
{
    $role = $candidate['role'] ?? null;
    if ( in_array($role, array('design_system', 'page'), true) ) {
        return (string) $role;
    }

    return null;
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_candidate_rank(array $candidate): int
{
    $score = isset($candidate['score']) && is_numeric($candidate['score']) ? (int) $candidate['score'] : 0;

    $devStatus = matrix_candidate_dev_status($candidate);
    if ( 'completed' === $devStatus ) {
        $score += 1200;
    } elseif ( 'ready_for_dev' === $devStatus ) {
        $score += 800;
    }

    // Page-type classification (#295): prefer real pages with known WP template
    // types. front_page first (it's the entry point), then single/archive/page,
    // and unknown last. This breaks dev-status ties when multiple dev-marked
    // frames compete (e.g., "Home Page – Desktop" beats "Blog Post – Desktop"
    // within the same ready_for_dev band).
    $pageType = (string) ($candidate['page_type'] ?? '');
    if ( 'front_page' === $pageType ) {
        $score += 300;
    } elseif ( in_array($pageType, array('single', 'archive', 'page'), true) ) {
        $score += 120;
    }

    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $type = strtoupper((string) ($candidate['type'] ?? ''));
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( 'FRAME' === $type ) {
        $score += 160;
    } elseif ( in_array($type, array('COMPONENT', 'INSTANCE'), true) ) {
        $score -= 400;
    }
    if ( in_array($parentType, array('CANVAS', 'SECTION'), true) ) {
        $score += 100;
    }
    if ( 1 === preg_match('/\b(home|homepage|desktop|page|website|landing|lp|archive|single|blog|theme|build|hosts?|agenc(?:y|ies)|pricing|features?|about|contact)\b/', $name . ' ' . $pageName) ) {
        $score += 200;
    }
    if ( ! matrix_is_reference_page_name($pageName) && 1 === preg_match('/\b(website comps?|pages?|screens?|final|production|dev handoff)\b/', $pageName) ) {
        $score += 240;
    }
    if ( 1 === preg_match('/\b(for hosts?|for agenc(?:y|ies)|pricing|features?|about|contact)\b/', $name) ) {
        $score += 160;
    }
    if ( 1 === preg_match('/\blp\s*i\s*([0-9]+)\b/', $name, $nameMatches) && 1 === preg_match('/\bi\s*([0-9]+)\b/', $pageName, $pageMatches) && $nameMatches[1] !== $pageMatches[1] ) {
        $score -= 90;
    }
    if ( 1 === preg_match('/\b(style guide|style tile|template|preview|core blocks|component|footer|header|menu)\b/', $name) ) {
        $score -= 250;
    }
    if ( 1 === preg_match('/\b(playground|presentation|addit?ional links?|graphics|imageries|exploration|scratch|old|deprecated|archive copy|copy\b|wip)\b/', $name . ' ' . $pageName) ) {
        $score -= 320;
    }
    if ( matrix_is_reference_page_name($pageName) ) {
        $score -= 360;
    }

    return $score;
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_candidate_bucket(array $candidate): string
{
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $haystack = $name . ' ' . $pageName;

    if ( 1 === preg_match('/\blp\s*i\s*([0-9]+)\b/', $haystack, $matches) ) {
        return 'landing:i' . $matches[1];
    }
    if ( 1 === preg_match('/\blp\b.*\bnew\b/', $haystack) ) {
        return 'landing:new';
    }
    if ( 1 === preg_match('/\bi\s*([0-9]+)\b/', $pageName, $matches) && 1 === preg_match('/\blp\b/', $name) ) {
        return 'landing:i' . $matches[1];
    }

    foreach ( array(
        'homepage' => '/\b(home|homepage)\b/',
        'hosts' => '/\bhosts?\b/',
        'agencies' => '/\bagenc(?:y|ies)\b/',
        'pricing' => '/\bpricing\b/',
        'features' => '/\bfeatures?\b/',
        'about' => '/\babout\b/',
        'contact' => '/\bcontact\b/',
        'archive' => '/\barchive\b/',
        'single' => '/\b(single|post page)\b/',
        'theme' => '/\b(theme|build)\b/',
        'landing' => '/\b(landing|lp)\b/',
    ) as $bucket => $pattern ) {
        if ( 1 === preg_match($pattern, $haystack) ) {
            return $bucket;
        }
    }

    $normalized = preg_replace('/\b(desktop|mobile|tablet|copy|wip|new|old|v\d+|i\d+)\b/', '', $name) ?? $name;
    $normalized = trim(preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '');
    return '' !== $normalized ? 'frame:' . $normalized : 'generic';
}

/**
 * @param array<string, mixed> $candidate
 * @return array<int, string>
 */
function matrix_candidate_selection_reasons(array $candidate): array
{
    $reasons = array();
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $type = strtoupper((string) ($candidate['type'] ?? ''));
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( 'FRAME' === $type ) {
        $reasons[] = 'transformable_frame';
    }
    if ( in_array($parentType, array('CANVAS', 'SECTION'), true) ) {
        $reasons[] = 'top_level_candidate';
    }
    if ( 1 === preg_match('/\b(home|homepage|website|landing|lp|archive|single|blog|theme|build|hosts?|agenc(?:y|ies)|pricing|features?|about|contact)\b/', $name . ' ' . $pageName) ) {
        $reasons[] = 'page_like_name';
    }
    if ( ! matrix_is_reference_page_name($pageName) && 1 === preg_match('/\b(website comps?|pages?|screens?|final|production|dev handoff)\b/', $pageName) ) {
        $reasons[] = 'page_collection';
    }

    return $reasons;
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_is_page_like_candidate(array $candidate): bool
{
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $type = strtoupper((string) ($candidate['type'] ?? ''));
    $width = isset($candidate['width']) && is_numeric($candidate['width']) ? (float) $candidate['width'] : 0.0;
    $height = isset($candidate['height']) && is_numeric($candidate['height']) ? (float) $candidate['height'] : 0.0;
    $textCount = isset($candidate['text_count']) && is_numeric($candidate['text_count']) ? (int) $candidate['text_count'] : 0;
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( 'FRAME' !== $type ) {
        return false;
    }
    // Role classification (#295): design_system frames are excluded upstream in
    // matrix_select_frame_ids(); guard here too so this function is safe to call
    // in isolation. When role is absent (older inspection), fall through to name
    // heuristics.
    if ( 'design_system' === matrix_candidate_role($candidate) ) {
        return false;
    }
    // Fallback name-based exclusion for frames without role classification.
    if ( null === matrix_candidate_role($candidate) ) {
        if ( 1 === preg_match('/\b(style guide|style tile|template|preview|core blocks|component|footer|header|menu)\b/', $name) ) {
            return false;
        }
        if ( 1 === preg_match('/\b(style guide|style tile|style tiles|presentation|template|templates|components)\b/', $pageName) ) {
            return false;
        }
    }

    return $width >= 900.0 && $width <= 2048.0 && $height >= 700.0 && $textCount > 0 && in_array($parentType, array('CANVAS', 'SECTION'), true);
}

function matrix_is_reference_page_name(string $pageName): bool
{
    return 1 === preg_match('/\b(research|screenshots?|captures?|references?|inspiration|moodboards?|audit|competitive)\b/', $pageName);
}
