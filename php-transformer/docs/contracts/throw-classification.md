# Throw classification

Every `throw new` in `src/`, and every call to a throwing helper, is classified in
[`tests/contract/fixtures/throw-classification.json`](../../tests/contract/fixtures/throw-classification.json).
`tests/contract/throw-classification.php` runs in `composer test`. It fails when a throw
site is unclassified, so a new limit cannot fail a whole import by accident. This is the
engine's guard for "degrade, don't throw" (Automattic/data-liberation-agent#761,
Automattic/blocks-engine#2713).

```sh
php tests/contract/throw-classification.php          # check, and print counts per class and subsystem
php tests/contract/throw-classification.php --list   # every site with its file, line and key
```

## Classes

| Class | Meaning | Examples |
| ----- | ------- | -------- |
| `integrity` | Security, path or tenant containment, artifact identity, stale hashes, tamper evidence, a missing entrypoint, or a malformed producer contract. Fails closed. | `WordPress site plan identity is missing, malformed, or stale.` |
| `operational` | A resource bound on the whole input or a missing runtime resource, rather than on one unit of content. | `Payload references exceed the compiler aggregate byte limit.` |
| `representability` | The source contains something one unit (item, binding, block, asset, page, route) can't express. Only that unit should be dropped or normalized, with a finding. | `html_include_count_exceeded` on a non-entry page |
| `programmer` | A caller wired or called the library wrongly. | `Composition requires a payload reader for referenced staged payloads.` |

`LogicException` and project classes that extend it are `integrity` automatically and are
not listed. PHP's SPL subclasses of `LogicException` are listed like any other class,
because `InvalidArgumentException` is this library's general validation throw.

## Classifying a new throw

1. Run the guard. It prints the file, line and key of the unclassified site.
2. Decide the class:
   - If the throw is reached only when the input or our own output is corrupt, unsafe or
     contradictory, use `integrity`.
   - If the input is valid but one unit of it can't be expressed, use `representability`.
     A per-item byte or count limit belongs here too, because one oversized item can trip
     it.
   - If the throw enforces a budget on the whole input, or reports a missing runtime
     resource, use `operational`.
   - If only a wrong call or wrong wiring reaches the throw, use `programmer`. A wiring
     check can also throw `LogicException`, which needs no entry.
3. Add the key under its file, with `"count"` set to the number of identical sites. The
   key is `Class(<first constructor argument>)` with whitespace removed, or
   `helper(<all arguments>)` for a helper call. Copy it from the guard output.
4. For `representability`, prefer degrading over listing. Drop the smallest unit, record a
   finding, and add a test that compiles a fixture to that finding. Then record
   `"unit"`, `"finding"` and `"test"`, where `test` is registered in `composer test`.
   Until that work is done, record `"unit"`, the proposed `"finding"` and
   `"pending": true`.

## Ratchet

`representability_pending_max` must equal the number of pending sites. It can only go
down: a new pending site fails the guard. When a conversion lands, replace `pending` with
the `test` that proves the finding, then lower the maximum.

## Converted sites and integrity backstops

A conversion usually moves the degrade into the builder that produces the plan. The
strict validator then stays as the integrity backstop, as #2712 and #2715 to #2718 do. An
`integrity` entry for such a backstop may name the upstream `"finding"` and its `"test"`.
This keeps each backstop traceable to its conversion.

A throw that stays in place and is caught at a unit boundary is `representability` with a
`test`. An example is a page-local include limit that leaves the page's includes
unexpanded. The same throw on the entrypoint still fails the compile.

## Throwing helpers

`THROW_CLASSIFICATION_HELPERS` in the guard lists methods that throw for their caller:
`WordPressSitePlan::invalidDeclaration()` and `WordPressSitePlan::unique()`. Their call
sites are classified, because the arguments decide the message. The guard fails when a
listed helper disappears, or when a new method declares a `never` return type outside the
list.

## Stale entries

The guard fails when an entry's count is higher than the number of live sites, including
an entry whose site no longer exists. When a throw is removed or reworded, update or
delete its entry in the same change.
