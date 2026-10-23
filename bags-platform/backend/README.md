## Alfarid: own manufacturing and dealer sales

Products are made by Alfarid. Dealers are customers of type `wholesale` or `distributor`; retail customers remain separate. Products no longer accept brand or supplier assignments. Historical brand, supplier and purchasing records remain in the database. Their API endpoints return 410, and supplier accounts cannot sign in through unified login.

### Production receipts

Apply the new migration with `php artisan migrate --force`.

- Backoffice: `#/production`. List and receive finished goods using `GET/POST /api/v1/admin/production-receipts`; `GET /api/v1/admin/production-receipts/options` provides accessible branches, locations and up to 50 matching variants.
- Viewing requires `inventory.view`. Receiving requires `inventory.adjust` and `products.view_cost`, with existing branch restrictions enforced.
- Send a unique `batch_number`, `branch_id`, optional `location_id` and `notes`, and 1–50 distinct `items` with `product_variant_id`, whole positive `quantity`, and nonnegative `unit_cost` (four decimal places). This is the entered cost of a finished unit; raw materials, bills of materials and work orders are not calculated by this screen.
- `Idempotency-Key` is required. Retain it across retries. Same key and payload replays the receipt; changed payload or an already received batch number returns 409. All lines, ledger movements and costs commit together.
- Receipts create `production_receipt` stock movements and update weighted average cost across physical stock in all branches, including damaged stock. They do not create supplier debt. As in the existing costing engine, concurrent sales do not acquire the variant cost lock, so quantities used for weighting are a transaction snapshot.
- Receipts are final audit records. Correct quantities through inventory adjustments with a reason. The migration refuses rollback once production has been recorded.

### Dealers

Backoffice: `#/dealers`. This uses the existing customer, approval and wholesale sales services. `GET /api/v1/admin/customers?business_only=1` includes wholesale customers and distributors, excluding retail customers.

New dealer accounts start pending. Save company details, upload documents with `POST /api/v1/admin/customers/{customer}/documents`, review documents and approve through the existing customer endpoints. Uploading requires `customers.update` and `customers.documents.view`; approval requires `customers.approve`. Documents stay in private storage, with a maximum of five files, PDF/JPEG/PNG up to 5 MB each.

Existing wholesale quotations, pricing, invoices, statements and payment notices continue to serve dealer sales. Existing customer returns and wallet accounting remain available.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
