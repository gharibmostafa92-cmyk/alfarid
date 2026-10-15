# API Notes

## Admin Categories (`/api/v1/admin/categories`)

- `GET /categories` — list with pagination (`per_page`, default 15, max 100), filters: `search`, `parent_id` (`root` for top-level), `is_active`. Use `?tree=1` for the full nested tree without pagination.
- `GET /categories/{id}` — single category with parent, children and products count.
- `POST /categories` — create (201). `slug` is generated automatically from `name_en` when omitted.
- `POST /categories/reorder` — body: `items: [{id, parent_id, sort_order}]`.
- `DELETE /categories/{id}` — fails with 422 when the category has children or linked products.
- Maximum category depth is 3 levels; violations return 422.

### Updating with a file upload (important)

PHP does not parse uploaded files from `multipart/form-data` when the request method is `PUT`.
Therefore, to update a category (or any resource) **with an image**, the frontend must send a
`POST` request to `/categories/{id}` with `_method=PUT` included in the form body.
Laravel converts it to a `PUT` request automatically (method spoofing), and validation,
authorization and file handling then work as expected.
