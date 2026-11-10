<?php
declare(strict_types=1);
namespace App\Support;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
final class StorageCleanupOwner
{
    public const MARKER = '.alfarid-cleanup-owner';
    public const SETTING_KEY = 'storage_cleanup_owner';

    public static function markerFor(string $owner): string
    {
        $connection = DB::connection();
        $identity = json_encode([
            $connection->getDriverName(),
            $connection->getConfig('write.host') ?? $connection->getConfig('host'),
            (string) ($connection->getConfig('write.port') ?? $connection->getConfig('port')),
            $connection->getDatabaseName(),
            $connection->getConfig('unix_socket'),
        ], JSON_THROW_ON_ERROR);

        return 'v1:'.hash_hmac('sha256', $identity, $owner);
    }

    public static function assertMatches(FilesystemAdapter $disk): void
    {
        $expected = trim((string) config('filesystems.cleanup_owner'));
        // A database dump can copy the setting. The bucket marker also binds it to the endpoint.
        $recorded = trim((string) DB::table('settings')->useWritePdo()->where('key', self::SETTING_KEY)->value('value'));
        if ($expected === '' || $recorded === '' || ! hash_equals($expected, $recorded)
            || ! $disk->exists(self::MARKER)
            || ! hash_equals(self::markerFor($expected), trim((string) $disk->get(self::MARKER)))) {
            throw new RuntimeException('Storage cleanup owner marker is missing or does not match this database.');
        }
    }
}
