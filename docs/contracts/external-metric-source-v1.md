# Generic external metric source v1

This contract declares a bounded public HTTP/JSON source and a metric extracted from it. It is source/metric data interpreted by the generic consumer runtime; it does not name executable adapters or a provider-specific field enum.

The declaration remains an entity in the existing `generic/external-metric/v1` collection. Source/operator provenance, captured fallback/hash, `generic/block-binding/v1` native Paragraph/Heading leaf, compilation staging, and shared-shell extraction remain unchanged.

## Entity example

```json
{
  "id": "github-stars",
  "source": {
    "schema": "generic/external-metric-source/v1",
    "id": "github.repository-information",
    "intent": "external_public_json",
    "request": {
      "method": "GET",
      "url_template": "https://api.github.com/repos/{owner}/{repository}",
      "query": {},
      "query_variables": [],
      "headers": {
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28"
      },
      "response_media_type": "application/json",
      "max_response_bytes": 1048576,
      "timeout_seconds": 5
    },
    "resource_variables": {
      "owner": {
        "location": "path",
        "min_length": 1,
        "max_length": 39,
        "allowed_characters": "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-",
        "first_characters": "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789",
        "last_characters": "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789",
        "prohibited_values": []
      },
      "repository": {
        "location": "path",
        "min_length": 1,
        "max_length": 100,
        "allowed_characters": "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-",
        "prohibited_values": [".", ".."]
      }
    },
    "resources": [{ "owner": "Automattic", "repository": ".github" }],
    "freshness": { "max_age_seconds": 86400 }
  },
  "metric": "stargazers_count",
  "extraction": {
    "kind": "json_pointer",
    "pointer": "/stargazers_count",
    "value_type": "nonnegative_integer"
  },
  "aggregation": "identity",
  "format": {
    "locale": "en-US",
    "grouping": true,
    "prefix": "",
    "suffix": "",
    "decimals": 0
  },
  "provenance": {
    "kind": "source_corroboration",
    "repository": "ndiego/nickdiego.com",
    "revision": "5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc",
    "source_path": "src/components/gh-repo-card.tsx"
  },
  "fallback": {
    "text": "7",
    "hash": "7902699be42c8a8e46fbbb4501726517e86b22c56a189f7625a6da49081b2451"
  },
  "bindings": [
    {
      "schema": "generic/block-binding/v1",
      "role": "paragraph",
      "source_path": "index.html",
      "search_block_markup": "<!-- wp:paragraph --><p>7</p><!-- /wp:paragraph -->",
      "occurrence": 1,
      "leaf": { "block": "core/paragraph", "attribute": "content" }
    }
  ]
}
```

For forks, `metric` and `extraction.pointer` become `forks_count`; fallback is the plain number `9`. `metric` is the fact's label/identity, not a provider-specific selector. Extraction path and output type are explicit data.

## Bounded declaration semantics

- Source object keys are `schema`, `id`, `intent`, `request`, `resource_variables`, `resources`, and `freshness`. Source IDs are stable bounded identifiers. `intent` is `external_public_json`; entity provenance remains mandatory and must be `source_corroboration` or `operator_mapping`.
- The request supports only `GET` over HTTPS, a JSON response, an explicit `Accept` header containing `json`, fixed query parameters, separately declared query variables, and path placeholders. URL templates reject userinfo, fragments, query syntax, nonstandard ports, malformed placeholders, and static `.`/`..` path segments. Placeholders can occur only in the path; runtime percent-encodes each path/query value. No request body, credentials, cookie jar, redirect control, or executable template is declared.
- `resource_variables` describes variable location, length bounds (1–255), literal allowed ASCII characters, optional first/last-character sets, and prohibited values. Its keys must equal path placeholders plus query variables. Each resource supplies exactly those values. This constraint grammar is generic data, not a provider field/name enum.
- Responses are bounded to at most 1 MiB and five seconds per request; an aggregate lookup is capped at 15 seconds and 100 resources. Runtime redirects are disabled and resolved destinations must not be loopback, private, link-local, or reserved. Freshness is explicit from 60 seconds to 30 days.
- `extraction` is optional only for `success_count`; when present it is a bounded RFC 6901 JSON Pointer plus `value_type`: `nonnegative_integer` (integer/canonical integer string within the safe-integer limit) or bounded `string` (UTF-8, nonempty, at most 255 bytes, no controls). Pointer depth is at most 32 and pointer length at most 1024 bytes. No wildcards, regular expressions, scripts, or implicit coercions.
- `aggregation` is `identity`, `sum`, or `success_count`. Identity requires one resource. Sum requires nonnegative integers, checks overflow, and fails atomically on any invalid resource. Success count starts with a valid HTTP 200 JSON object; if typed extraction is present, the pointer must exist and pass its declared type for that resource to contribute one. The extracted value is only a validity prerequisite and is never summed/returned. If extraction is absent, any valid HTTP 200 JSON object contributes one. No provider-specific error field/key is checked, and no arbitrary aggregation operator is supported.
- Formatting retains explicit locale/grouping/prefix/suffix/decimals. Decimals are 0–4; literal prefix/suffix are at most 16 bytes and reject controls and angle brackets. String identity uses no grouping, decimals, or suffix. No metric-specific formatter switch exists.
- A canonical request/source identity is shared across facts so values extracted from one response (e.g. stars and forks) can reuse one fetch/cache entry. Failure, invalid JSON/type, timeout, partial sum, or out-of-bound result never becomes zero: retain existing backoff, last-known-good, captured fallback, freshness receipt, and unresolved behavior.

## Configuration-only recipes

For `success_count`, resources are evaluated independently. An HTTP 200 JSON object contributes one only when its optional typed pointer exists and passes type validation; a missing or invalid selected value contributes no success. The extracted value is never summed, returned, or inspected for a provider-specific error key. Transport/HTTP failures use the shared runtime fallback and receipt semantics.

The aggregate remains atomic: if any configured resource has a transport/HTTP/JSON/type failure, the success_count refresh fails and uses the shared stale/fallback receipt rather than exposing a partial count. A successful all-resource lookup returns the number of resources whose optional typed extraction passed (or all resources when extraction is omitted).

- **WordPress.org plugin information:** URL `https://api.wordpress.org/plugins/info/1.2/`, fixed query `action=plugin_information`, slug query resources with lowercase alphanumeric/hyphen constraints, freshness 3600 seconds. `active_installs` maps `/active_installs` to integer/sum; `num_ratings` maps `/num_ratings` to integer/identity; `version` maps `/version` to bounded string/identity; `plugin_response_count` uses success_count. Existing formats preserve install `+` and version `v`.
- **WordPress.org download history:** URL `https://api.wordpress.org/stats/plugin/1.0/downloads.php`, fixed query `historical_summary=1`, slug query resources, freshness 3600 seconds. `downloads_all_time` maps `/all_time` to integer/sum.
- **GitHub repository information:** URL template `https://api.github.com/repos/{owner}/{repository}`, owner/repository path resources with the constraints shown above, freshness 86400 seconds. Stars and forks are JSON-pointer integer identities.
- **Neutral source:** `https://metrics.example.com/v1/records/{record}`, a `record=sample` path resource, freshness 600 seconds, and response `{"measurements":{"score":31}}`. Metric `score` extracts `/measurements/score` as integer/identity, with explicit operator mapping and plain-number format. This recipe is accepted/transported without source-specific PHP/JS core code; producer tests do not perform the HTTP request.

## Ownership and paired acceptance

SSI #1996 owns one generic HTTP/JSON request/cache/backoff/receipt/native-binding/editor/companion/export/reimport lifecycle. WordPress.org, GitHub, and neutral endpoints are data recipes; adding a source must not add source-specific core request/extraction/format switches or parallel readers. Blocks Engine validates and transports this same contract while preserving provenance/fallback/native-leaf/staged/shared-shell semantics.

Producer checks cover all recipes, malformed request/resource/JSON-pointer/type/operator/format/freshness/provenance cases, full and staged compilation, shared-shell reanchoring, serialization, and WordPress 7.1 parse/save/reparse. SSI paired acceptance retains all shipped WordPress.org GUI/literal/cache/failure/standalone/export proofs and exercises GitHub plus the neutral source through the same runtime/editor/refresh/export/reimport lifecycle. #2541 remains draft until the paired generic runtime proof passes.
