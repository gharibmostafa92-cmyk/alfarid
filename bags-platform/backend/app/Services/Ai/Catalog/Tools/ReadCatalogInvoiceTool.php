<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\User;
use App\Services\Ai\Inventory\Tools\ReadItemDocumentTool;

final class ReadCatalogInvoiceTool extends ReadItemDocumentTool
{
    public function name(): string
    {
        return 'read_catalog_invoice';
    }

    public function permissions(): array
    {
        return ['products.create'];
    }

    public function declaration(): array
    {
        $declaration = parent::declaration();
        $declaration['description'] = 'قراءة بنود فاتورة ومطابقتها بالكتالوج. مرر رقم المورد فقط إذا حدده المستخدم صراحة.';
        $declaration['parameters']['properties']['supplier_id'] = ['type' => 'INTEGER'];

        return $declaration;
    }

    protected function visionSetting(): string
    {
        return 'ai_catalog_vision_enabled';
    }

    protected function canRead(User $actor): bool
    {
        return $actor->can('products.create') && $actor->can('ai_agent.catalog.vision');
    }
}
