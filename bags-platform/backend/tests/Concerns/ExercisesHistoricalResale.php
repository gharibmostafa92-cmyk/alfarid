<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Http\Middleware\ManufacturingBusiness;

/**
 * Exercise retained resale domain rules below their retired HTTP boundary.
 *
 * Only the business-retirement gate is excluded in these historical regression
 * scenarios. Authentication, authorization, validation and financial guards stay
 * enabled. ManufacturingBusinessTest separately exercises the real HTTP gate.
 */
trait ExercisesHistoricalResale
{
    protected function exerciseHistoricalResale(): void
    {
        $this->withoutMiddleware(ManufacturingBusiness::class);
    }
}
