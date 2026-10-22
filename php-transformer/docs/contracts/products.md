# Products collection

## Small and empty controlled listings

Page-owned sort, facet, or result-count controls are commerce evidence for a
qualifying product card, even below the image-only grid repetition threshold.
Uncontrolled image-only grids still require four qualifying cards.

An empty collection with recognized controls can declare a
`generic/product-grids/v1` entity without a `products` collection. Its `items`
is an empty array, its binding anchors the empty-state region, and
`item_template` is absent because there is no card to repeat. Nonempty grids
retain their existing item-template contract.

The corresponding `generic/product-listings/v1` entity keeps its existing
shape: `source_path`, `selector`, `grid_selector`, `controls`, and `bindings`.
`grid_selector` references the empty grid entity; its `items: []` is the empty
item set. The listing's first binding anchors the enclosing region and the
remaining bindings anchor each control. Checkbox facets use the additive
`shape: "checkboxes"` value, including when there are no cards to partition.

Sort selects recognize ordering values independently of localized labels;
`sort`/`order`-named selects also qualify without card-order proof. Known
options retain normalized `order` values (including `oldest` for ascending
creation/date order); unknown options on explicitly named selects retain their
source value and label without inventing an order. Forms entirely owned by
listing controls are not also declared as generic provider forms.

Empty candidates require an empty-state signal, not checkbox controls alone.
They are suppressed when any populated product grid qualifies. Control lookup
includes the nearest landmark but does not cross it, so unrelated promoted
cards outside the collection's landmark do not veto an empty listing.

## Product rows

`generic/products/v1` is the provider-neutral `products` entity collection for
shop imports. Rows retain `name`, `slug`, `regular_price` and existing optional
`description`, `category`, `image`, `sale_price`, `attributes` and source/binding
metadata. Prices are decimal strings. Existing card fields win when JSON-LD
supplies evidence for the same slug; missing commerce fields are filled in.

Schema.org `Product` and `ProductGroup` evidence can additionally supply:

- `sku`: URL-decoded merchant SKU. An unlabelled `productGroupID` is not a SKU;
  a group can explicitly publish `sku` or a `productGroupID` PropertyValue
  labelled `sku` by `propertyID` or `name`.
- `images`: ordered `{src, alt}` rows, group images first, then variant images,
  deduplicated by source URL. String and ImageObject images are accepted. Alt
  text uses caption/name, falling back to the product name. `image` remains the
  first schema image (an existing card image still wins on merge). A legacy
  single-image Product does not gain a redundant gallery field.
- `tags`: trimmed, deduplicated keywords from a comma string or list.
- `stock_status`: `instock` for InStock, LimitedAvailability, PreOrder, BackOrder;
  `outofstock` for OutOfStock, SoldOut, Discontinued. Bare names and schema.org
  HTTP/HTTPS URLs are accepted.
- `variants`: ordered `{sku?, regular_price, sale_price?, stock_status, image?,
  attributes}` rows from `hasVariant` Products. Unknown variant stock defaults
  to `instock`. Attributes use `{key, label, value, value_label}` with slug keys
  and values. Sources are additionalProperty PropertyValue name/value pairs,
  and size/color/material/pattern properties explicitly named in `variesBy`.
  Display names are never parsed as options.

An offer's StrikethroughPrice or ListPrice priceSpecification above its price
supplies `regular_price`, with the offer price in `sale_price`, for Products and
variants alike. A group uses the lowest variant regular price and retains its
own name, description and category.

Variants are emitted only if every child has usable price evidence and at least
one declared attribute, and all attribute combinations are distinct. Otherwise
the group stays a simple product, with the lowest price and gallery, but no
variant SKUs or variants field. A `product_variants_undeclared` warning names the
source path and slug. Child variants are not emitted as standalone products.
