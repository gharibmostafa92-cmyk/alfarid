<?php

namespace Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
    }

    protected function fakeUploads(): FilesystemAdapter
    {
        $disk = Storage::fake('r2');
        $disk->buildTemporaryUrlsUsing(fn ($path, $expiration, $options) => 'https://storage.example.test/'.rawurlencode($path).'?signed=1');

        return $disk;
    }
}
