<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/**
 * Blocks Engine's producer-neutral capture-evidence vocabulary.
 *
 * A capture tool records what it observed in the rendered source (document
 * variants, dialog and disclosure bindings, galleries, collections, glyph
 * colour, ...) as attributes, classes and sidecar schemas on the captured
 * HTML. These names are the input contract Blocks Engine reads; any capture
 * producer may emit them. Values and their grammar are documented in
 * docs/contracts/capture-evidence.md. Every name the transformer reads or
 * emits is declared here once and referenced by constant; the
 * capture-evidence contract test fails on a literal elsewhere in src/ and on
 * an entry nothing uses. Names listed in PUBLISHED_FOR_DOWNSTREAM are not read
 * by Blocks Engine; they are published so downstream importers and producers
 * share one spelling.
 */
final class CaptureEvidence
{
    /** Prefix of every capture-evidence data attribute. */
    public const ATTRIBUTE_PREFIX = 'data-blocks-engine-capture-';

    // Document variants and responsive documents.

    /** Presence marks an element as a body-equivalent captured document root. */
    public const DOCUMENT_SCOPE = 'data-blocks-engine-capture-document-scope';
    /** Profile identifier (`[a-z][a-z0-9_-]{0,63}`) of a declared document scope. */
    public const DEVICE_DOCUMENT = 'data-blocks-engine-capture-device-document';
    /** Class on the root of the mobile copy of a responsive capture. */
    public const MOBILE_DOCUMENT_CLASS = 'data-blocks-engine-capture-mobile-document';
    /** Class on the root of the desktop (default) copy of a responsive capture. */
    public const DESKTOP_DOCUMENT_CLASS = 'data-blocks-engine-capture-desktop-document';
    /** Class prefix pairing responsive counterparts; followed by 12 lowercase hex digits. */
    public const RESPONSIVE_COUNTERPART_CLASS_PREFIX = 'data-blocks-engine-capture-responsive-counterpart-';
    /** Source identity of a responsive counterpart element. */
    public const RESPONSIVE_SOURCE = 'data-blocks-engine-capture-responsive-source';
    /** Id suffix prefix for a non-default variant's copy of a shared id: `<id>--blocks-engine-capture-<variant>`. */
    public const VARIANT_ID_SUFFIX_PREFIX = '--data-blocks-engine-capture-';
    /** On `<style>`/`<link>`: the authored media condition, independent of the serialized `media` activation state. */
    public const SOURCE_MEDIA = 'data-blocks-engine-capture-source-media';

    // Dialogs, dropdowns and their runtime state.

    /** Dialog binding key on the control that opens a captured panel. */
    public const DIALOG_TRIGGER = 'data-blocks-engine-capture-dialog-trigger';
    /** Dialog binding key on the captured panel; equals its trigger's key. */
    public const DIALOG_PANEL = 'data-blocks-engine-capture-dialog-panel';
    /** On a close control: the key of the dialog it closes. */
    public const DIALOG_CLOSE = 'data-blocks-engine-capture-dialog-close';
    /** JSON snapshot of the opened panel state, on the trigger. */
    public const DIALOG_PANEL_STATE = 'data-blocks-engine-capture-dialog-panel-state';
    /** JSON resting/opened attribute snapshots on an existing in-place panel. */
    public const EXISTING_PANEL = 'data-blocks-engine-capture-existing-panel';
    /** JSON list of ancestor attribute bindings toggled with the panel, on the trigger. */
    public const DIALOG_ANCESTOR_STATE = 'data-blocks-engine-capture-dialog-ancestor-state';
    /** Reason the ancestor bindings could not be verified (for example `ancestor-limit`); carried onto the native dialog. */
    public const DIALOG_ANCESTOR_UNVERIFIED = 'data-blocks-engine-capture-dialog-ancestor-unverified';
    /** Presence: the panel was observed in place rather than in a portal. */
    public const OBSERVED_PLACEMENT = 'data-blocks-engine-capture-observed-placement';
    /** Class on an in-place dropdown panel. */
    public const DROPDOWN_CLASS = 'data-blocks-engine-capture-dropdown';
    /** Class on a captured dialog panel wrapper. */
    public const DIALOG_CLASS = 'data-blocks-engine-capture-dialog';
    /** Class on a captured `<details>` disclosure wrapper. */
    public const DISCLOSURE_CLASS = 'data-blocks-engine-capture-disclosure';

    // Disclosures.

    /** Marker on the capture's generated disclosure interpreter `<script>`. */
    public const DISCLOSURE_RUNTIME = 'data-blocks-engine-capture-disclosure-runtime';
    /** Observed accessible label of a disclosure control. */
    public const DISCLOSURE_LABEL = 'data-blocks-engine-capture-disclosure-label';
    /** Prefix of an SVG attribute value observed in the open state: `<prefix><attribute>`. */
    public const DISCLOSURE_OPEN_PREFIX = 'data-blocks-engine-capture-disclosure-open-';
    /** Prefix of an SVG attribute value observed in the closed state: `<prefix><attribute>`. */
    public const DISCLOSURE_CLOSED_PREFIX = 'data-blocks-engine-capture-disclosure-closed-';
    /** `true` on a disclosure panel driven by the capture's local disclosure shim. */
    public const LOCAL_DISCLOSURE = 'data-blocks-engine-capture-local-disclosure';
    /** Marker on the local disclosure shim `<script>`. */
    public const LOCAL_DISCLOSURE_RUNTIME = 'data-blocks-engine-capture-local-disclosure-runtime';
    /** `true` on a container whose disclosures are mutually exclusive. */
    public const EXCLUSIVE_DISCLOSURES = 'data-blocks-engine-capture-exclusive-disclosures';

    // Listboxes.

    /** Listbox binding key on the button that opens a captured listbox. */
    public const LISTBOX_TRIGGER = 'data-blocks-engine-capture-listbox-trigger';
    /** Listbox binding key on the captured option panel; equals its trigger's key. */
    public const LISTBOX_PANEL = 'data-blocks-engine-capture-listbox-panel';

    // Galleries.

    /** Presence marks a captured gallery root. */
    public const GALLERY = 'data-blocks-engine-capture-gallery';
    /** JSON frame sequence of a captured gallery root. */
    public const GALLERY_SEQUENCE = 'data-blocks-engine-capture-gallery-sequence';
    /** Integer slot offset of a gallery image relative to the current frame. */
    public const GALLERY_SLOT = 'data-blocks-engine-capture-gallery-slot';
    /** Presence marks the gallery stage element. */
    public const GALLERY_STAGE = 'data-blocks-engine-capture-gallery-stage';
    /** Integer index of one captured frame inside the gallery stage. */
    public const GALLERY_FRAME = 'data-blocks-engine-capture-gallery-frame';
    /** Integer initial frame of a gallery list. */
    public const GALLERY_INITIAL = 'data-blocks-engine-capture-gallery-initial';
    /** Observed CSS `display` keyword of a gallery item. */
    public const GALLERY_DISPLAY = 'data-blocks-engine-capture-gallery-display';
    /** `-1` (previous) or `1` (next) on a gallery control. */
    public const GALLERY_DIRECTION = 'data-blocks-engine-capture-gallery-direction';
    /** Source frame index carried on an emitted gallery item. */
    public const GALLERY_INDEX = 'data-blocks-engine-capture-gallery-index';
    /** Selector binding a gallery root to its interaction evidence. */
    public const GALLERY_SOURCE = 'data-blocks-engine-capture-gallery-source';
    /** Viewport width the gallery root was captured at. */
    public const GALLERY_CAPTURE_WIDTH = 'data-blocks-engine-capture-gallery-capture-width';
    /** Marker on the capture's gallery helper `<script>`. */
    public const GALLERY_RUNTIME = 'data-blocks-engine-capture-gallery-runtime';

    // Collections and filters.

    /** Collection id on a collection's item container. */
    public const COLLECTION = 'data-blocks-engine-capture-collection';
    /** Collection id on the collection's search field. */
    public const COLLECTION_FIELD = 'data-blocks-engine-capture-collection-field';
    /** Collection id on a category control. */
    public const COLLECTION_CATEGORY_CONTROL = 'data-blocks-engine-capture-collection-category-control';
    /** Category index of a category control. */
    public const COLLECTION_INDEX = 'data-blocks-engine-capture-collection-index';
    /** Collection id on the collection's empty state. */
    public const COLLECTION_EMPTY = 'data-blocks-engine-capture-collection-empty';
    /** Canonical item key of a collection item. */
    public const COLLECTION_ITEM = 'data-blocks-engine-capture-collection-item';
    /** JSON list of category memberships of a collection item. */
    public const COLLECTION_MEMBERS = 'data-blocks-engine-capture-collection-members';
    /** Copy id of a collection status label. */
    public const COLLECTION_STATUS = 'data-blocks-engine-capture-collection-status';
    /** Marker on the capture's collection helper `<script>`. */
    public const COLLECTION_RUNTIME = 'data-blocks-engine-capture-collection-runtime';
    /** Marker on the capture's collection visibility `<style>`. */
    public const COLLECTION_VISIBILITY = 'data-blocks-engine-capture-collection-visibility';
    /** Status label template with `{count}` / `{query}` tokens. */
    public const STATUS_TEMPLATE = 'data-blocks-engine-capture-status-template';
    /** Presence: the status label hides at a zero count. */
    public const STATUS_HIDE_ZERO = 'data-blocks-engine-capture-status-hide-zero';
    /** Probe token a finite-bootstrap status template must not contain. */
    public const NO_MATCH_PROBE = 'data-blocks-engine-capture-no-match';
    /** Probe token a finite-bootstrap status template must not contain. */
    public const FINITE_PROBE = 'data-blocks-engine-capture-finite-probe';

    // Choice groups and native controls.

    /** Config key of a choice group the capture's choice runtime replays. */
    public const CHOICE_GROUP = 'data-blocks-engine-capture-choice-group';
    /** Capture-internal option index; stripped from projected choice markup. */
    public const CHOICE_INDEX = 'data-blocks-engine-capture-choice-index';
    /** Marker on the capture's choice-group runtime `<script>`. */
    public const CHOICE_RUNTIME = 'data-blocks-engine-capture-choice-runtime';
    /**
     * JSON version-1 native control state facts. Optional boolean `rendered`
     * records observed rendering: false makes the control non-authored;
     * omission preserves classification from markup alone.
     */
    public const NATIVE_CONTROL_STATE = 'data-blocks-engine-capture-native-control-state';
    /** Marker on the capture's native control helper `<script>`. */
    public const NATIVE_CONTROL_RUNTIME = 'data-blocks-engine-capture-native-control-runtime';

    // Presentation and diagnostics.

    /** Rendered glyph colour (`#rrggbb`) of an icon glyph. */
    public const GLYPH_COLOR = 'data-blocks-engine-capture-glyph-color';
    /** Diagnostic recording an anchor the capture could not resolve; not render-significant. */
    public const ANCHOR_UNRESOLVED = 'data-blocks-engine-capture-anchor-unresolved';
    /** Recorded scroll-entrance states of an element the source reveals on viewport entry. */
    public const VIEWPORT_ENTRANCE = 'data-blocks-engine-capture-viewport-entrance';
    /** Marks the capture's viewport-entrance activator script; engine-owned, not source behavior. */
    public const VIEWPORT_ENTRANCE_ACTIVATOR = 'data-blocks-engine-capture-viewport-entrances';

    // Sidecar schemas.

    /** `interaction-states.json`: captured interaction report. */
    public const CAPTURED_INTERACTIONS_SCHEMA = 'blocks-engine-capture/captured-interactions/v1';
    /** Capture receipt. */
    public const CAPTURE_RECEIPT_SCHEMA = 'blocks-engine-capture/capture-receipt/v1';
    /** Capture receipt `sourceInteractivity` record. */
    public const SOURCE_INTERACTIVITY_SCHEMA = 'blocks-engine-capture/source-interactivity/v1';
    /** Collection filter `finiteBootstrap` record. */
    public const FINITE_BOOTSTRAP_SCHEMA = 'blocks-engine-capture/finite-bootstrap/v1';
    /** Collection filter `finiteBootstrap.status` record. */
    public const COLLECTION_STATUS_SCHEMA = 'blocks-engine-capture/collection-status/v1';

    // Recognized capture runtimes: sha256 of the trimmed `<script>` body.

    /** The site-independent disclosure interpreter (DISCLOSURE_RUNTIME). */
    public const DISCLOSURE_RUNTIME_SHA256 = 'b545f43a77b79d075373048431101fe11d7ff8cdfe7987de6d7971512763d88f';
    /** The gallery helper (GALLERY_RUNTIME) that may coexist with a replaced disclosure interpreter. */
    public const GALLERY_RUNTIME_SHA256 = '456af76323601e51d851d3a4d6fe4ff6e3989c9969562f425fd72103c5c57c6b';
    /** The choice-group runtime (CHOICE_GROUP_RUNTIME) with an empty config list (`var configs=[];`). */
    public const CHOICE_RUNTIME_SHA256 = '82806634d813434b72ca5cbc9532c88f26aa4bd3cec880c2e6c12580dd6849eb';

    // Published for downstream importers; Blocks Engine does not read these.

    /** Marker on the capture's device-selection bootstrap `<script>` in `<head>`. */
    public const DEVICE_SELECTION = 'data-blocks-engine-capture-device-selection';
    /** Marker on the capture's device-document overlay `<script>` at the start of `<body>`. */
    public const DEVICE_BODY = 'data-blocks-engine-capture-device-body';
    /** On `<html>`: the profile the device-selection runtime selected; written at runtime. */
    public const SELECTED_DOCUMENT = 'data-blocks-engine-capture-selected-document';
    /** Marker on the `<meta name="viewport">` the device-selection runtime fills. */
    public const SELECTED_VIEWPORT = 'data-blocks-engine-capture-selected-viewport';
    /** On `<html>`: set at runtime when no captured document applies to the device. */
    public const DOCUMENT_UNAVAILABLE = 'data-blocks-engine-capture-document-unavailable';
    /** On `<style>`/`<link>`: the device profile the stylesheet belongs to. */
    public const DEVICE_STYLE = 'data-blocks-engine-capture-device-style';
    /** Member-login provider on a link that leads to a member login. */
    public const MEMBER_LOGIN = 'data-blocks-engine-capture-member-login';
    /** Class prefix marking a member-login link: `<prefix><provider>`. */
    public const MEMBER_LOGIN_CLASS_PREFIX = 'data-blocks-engine-capture-member-login-';

    /** Constants above that are published for downstream importers only. */
    public const PUBLISHED_FOR_DOWNSTREAM = array(
        'DEVICE_SELECTION',
        'DEVICE_BODY',
        'SELECTED_DOCUMENT',
        'SELECTED_VIEWPORT',
        'DOCUMENT_UNAVAILABLE',
        'DEVICE_STYLE',
        'MEMBER_LOGIN',
        'MEMBER_LOGIN_CLASS_PREFIX',
    );
}
