# API Notes

## Admin Categories (`/api/v1/admin/categories`)

- `GET /categories` — list with pagination (`per_page`, default 15, max 100), filters: `search`, `parent_id` (`root` for top-level), `is_active`. Use `?tree=1` for the full nested tree without pagination.
- `GET /categories/{id}` — single category with parent, children and products count.
- `POST /categories` — create (201). `slug` is generated automatically from `name_en` when omitted.
- `PUT /categories/reorder` — body: `items: [{id, parent_id, sort_order}]`.
- `DELETE /categories/{id}` — fails with 422 when the category has children or linked products.
- Maximum category depth is 3 levels; violations return 422.

## Admin Brands (`/api/v1/admin/brands`)

- Same pattern as categories, without parent/tree/reorder. Logo is uploaded in the `logo` field
  (jpg, jpeg, png, webp, **svg** allowed) and returned as `logo_url`.
- Deleting a brand linked to products fails with 422.

## Admin Products (`/api/v1/admin/products`)

- `GET /products` — filters: `search` (names, `code`, variant `sku`/`barcode`), `category_id`
  (add `include_children=1` to include subcategories), `brand_id`, `status`, `sales_channel`,
  `is_featured`; `sort`: `latest` (default), `oldest`, `name`. Response includes
  `variants_count`, `min_retail_price`, `max_retail_price`.
- `POST /products` — creates the product as `draft` first and auto-assigns `code` (`P00001`…).
  Optional `variants: [...]` can be sent with creation; a variant without `barcode` gets an
  internal EAN-13 barcode (prefix `2`), and without `sku` gets `{product_code}-{n}`.
- Publishing (`status: published`) requires the `products.publish` permission and a publishable
  product: at least one active variant, retail/wholesale prices on all active variants according
  to `sales_channel`, at least one image, and an active category. Violations return 422 with
  `errors.status` listing every problem; missing publish permission returns 403.
- `cost_price` is only visible to users with the `products.view_cost` permission.
- `DELETE /products/{id}` — soft-deletes the product and its variants.

## Product Variants (`/api/v1/admin/products/{product}/variants`)

- Nested and scoped: a variant id under the wrong product returns 404.
- `GET` returns all variants without pagination. Create/update/delete require `products.update`
  (editing variants is editing the product); read requires `products.view`.
- The combination (color_name_en, size, capacity) must be unique per product — duplicates return 422.
- A variant without `sku` gets `{product_code}-{NN}` (e.g. `P00001-01`); without `barcode` it gets
  an internal EAN-13 barcode (prefix `2`).
- The last active variant of a published product cannot be deleted or deactivated (422).
- Sending `cost_price` without the `products.view_cost` permission is silently ignored on write.

## Product Media (`/api/v1/admin/products/{product}/media`)

- `GET` lists the product's media **and** its variants' media ordered by `order_column`
  (variant media items carry `variant_id`).
- `POST` with `collection=images` + `files[]` (max 10, jpg/png/webp, 5MB each), optionally with
  `variant_id` to attach the images to a variant instead. With `collection=video` + `file`
  (mp4/webm/quicktime, 50MB) — video is product-only; sending it with `variant_id` returns 422.
- `PUT /media/reorder` with `ids: [...]` in the desired order.
- `DELETE /media/{media}` — the last image of a published product cannot be deleted (422).

### Updating with a file upload (important)

PHP does not parse uploaded files from `multipart/form-data` when the request method is `PUT`.
Therefore, to update a category (or any resource) **with an image**, the frontend must send a
`POST` request to `/categories/{id}` with `_method=PUT` included in the form body.
Laravel converts it to a `PUT` request automatically (method spoofing), and validation,
authorization and file handling then work as expected.
