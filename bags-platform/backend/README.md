## Returns, procurement and wallet API

Deploy this update with:

```sh
php artisan migrate --force
php artisan db:seed --class=FinancialPermissionSeeder --force
```

Reconcile existing duplicate supplier invoice numbers or noncash payment references before installing the unique indexes. The migration preserves financial records instead of deleting duplicates. Existing wallet balances become opening ledger entries. Legacy public return images move to private storage, with public copies removed after the migration commits.

- Send `Idempotency-Key` when receiving a PO, creating a supplier payment, or submitting an ASN. Use a UUID, or up to 128 ASCII letters/digits and `._:-`. Generate once per intended operation and retain across retries. Different data under the same key returns 409. The key commits with the business effects; failed operations do not consume it. Keys are scoped to actor and operation. Replays return the current associated document without posting the operation twice.
- Sending a PO requires approval. Editing supplier, branch, lines, discount or delivery date clears approval. Approval, receipt, invoice posting/cancellation, payment creation/update/deletion, and RMA approval/receipt/refund have separate permissions. These are seeded for Super Admin; assign other roles explicitly.
- Bag quantities are whole units, with at most 50 lines and 99,999,999 units per line. RMA submission and supplier portal requests are rate limited.
- Receipt must specify `supplier_delivery_id` if an ASN is pending. `POST /api/v1/admin/purchase-orders/{id}/close-short` closes a partially received PO without changing received quantities, and rejects pending ASNs. Cancelling an unreceived PO rejects ASNs and releases its internal request for conversion again.
- WAC uses unit cost net of the PO header discount, with four decimal places. Physical `on_hand` across branches includes damaged units; damaged remains a subset of physical stock. Concurrent sales do not acquire the variant cost lock, so the quantity sum is a transaction snapshot.
- Suppliers cannot view draft POs or internal payment notes. Statement periods default to the latest 365 days and are capped at 366 days; the opening balance includes prior movements.
- Customers can list/show their own returns using `GET /api/v1/store/account/returns` and `GET /api/v1/store/account/returns/{id}`. Attachment URLs require authentication. Invoice resources expose `attachment_url`; deliveries expose `invoice_file_url`.
- `GET /api/v1/store/account/wallet` returns the customer's balance and paginated ledger. Approved customers may checkout with `payment_method: wallet` if balance covers the complete order, including shipping/tax without COD fees. Split payments are not supported. Debit, order creation, stock reservation and coupon usage commit atomically. Existing checkout idempotency prevents duplicate charges. Cancellation or a legacy full-order return reverses the wallet debit once. RMA refunds create their own credits without also reversing that debit. External payment callbacks cannot settle wallet orders.
- Wallet entries are append-only and reference-unique. Balances cannot be negative. No unrestricted top-up or balance-edit endpoint is introduced.

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
