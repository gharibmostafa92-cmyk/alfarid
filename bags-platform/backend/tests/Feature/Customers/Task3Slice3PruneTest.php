<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Console\Commands\Customer\PruneCustomerArtifacts;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter as FlysystemAdapterContract;
use League\Flysystem\FilesystemOperator;
use ReflectionMethod;
use Tests\TestCase;

class Task3Slice3PruneTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prune_warns_when_cleanup_owner_is_not_set(): void
    {
        config(['filesystems.cleanup_owner' => null]);

        $this->artisan('customers:prune-artifacts')
            ->expectsOutputToContain('cleanup_owner')
            ->assertExitCode(0);
    }

    public function test_prune_deletes_only_listed_files_never_the_prefix(): void
    {
        $directory = 'customer_documents/pending/22222222-3333-4444-8555-666666666666';
        $oldFile = $directory.'/old.pdf';
        $oldTimestamp = now()->subHours(2)->timestamp;

        $driver = \Mockery::mock(FilesystemOperator::class);
        $driver->shouldReceive('listContents')
            ->once()
            ->with($directory, true)
            ->andReturn(new DirectoryListing([
                new FileAttributes($oldFile, fileSize: 3, lastModified: $oldTimestamp),
            ]));
        // A file uploaded after the listing must never be touched: the fix
        // deletes exactly the observed keys instead of the whole prefix.
        $driver->shouldReceive('delete')
            ->once()
            ->with($oldFile)
            ->andReturnTrue();
        $driver->shouldNotReceive('deleteDirectory');

        $disk = new LaravelFilesystemAdapter($driver, \Mockery::mock(FlysystemAdapterContract::class));
        $command = new PruneCustomerArtifacts;
        $method = new ReflectionMethod($command, 'pruneDirectoryIfInactive');
        $failures = 0;
        $arguments = [$disk, $directory, now()->subHour()->timestamp, &$failures];
        $method->invokeArgs($command, $arguments);

        $this->assertSame(0, $failures);
    }
}
