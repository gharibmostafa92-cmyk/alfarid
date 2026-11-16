<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\Customer\PruneCustomerArtifacts;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter as FlysystemAdapterContract;
use League\Flysystem\FilesystemOperator;
use ReflectionMethod;
use Tests\TestCase;

class PruneCustomerArtifactsListingTest extends TestCase
{
    public function test_recent_files_use_recursive_listing_metadata_without_per_file_head_requests(): void
    {
        $directory = 'customer_documents/pending/11111111-2222-4333-8444-555555555555';
        $driver = \Mockery::mock(FilesystemOperator::class);
        $driver->shouldReceive('listContents')
            ->once()
            ->with($directory, true)
            ->andReturn(new DirectoryListing([
                new FileAttributes($directory.'/first.pdf', lastModified: now()->timestamp),
                new FileAttributes($directory.'/nested/second.pdf', lastModified: now()->timestamp),
            ]));
        $driver->shouldNotReceive('lastModified');
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
