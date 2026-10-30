<?php

declare(strict_types=1);

namespace App\Services\Ai;

enum ToolKind: string
{
    case Read = 'read';
    case Write = 'write';
    case Ui = 'ui';
}
