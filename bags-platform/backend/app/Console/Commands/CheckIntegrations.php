<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CheckIntegrations extends Command
{
    protected $signature = 'integrations:check {--live : Test R2 with a temporary image and send a minimal Gemini request}';

    protected $description = 'Verify R2 and Gemini configuration without displaying credentials';

    public function handle(GeminiClient $gemini): int
    {
        foreach (['key', 'secret', 'bucket', 'endpoint', 'root'] as $key) {
            if (! config('filesystems.disks.r2.'.$key)) {
                $this->error('R2 configuration is incomplete.');

                return self::FAILURE;
            }
        }
        if (! config('services.gemini.api_key') || ! config('services.gemini.model')) {
            $this->error('Gemini configuration is incomplete.');

            return self::FAILURE;
        }
        if (! $this->option('live')) {
            $this->info('R2 and Gemini are configured. Use --live to verify the services.');

            return self::SUCCESS;
        }

        $success = true;
        $path = '_setup-check/'.Str::uuid().'.png';
        $disk = Storage::disk('r2');
        $created = false;
        try {
            $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jBZkAAAAASUVORK5CYII=');
            $created = $disk->put($path, $image, ['ContentType' => 'image/png']);
            if (! $created || $disk->get($path) !== $image) {
                throw new RuntimeException('R2 verification failed.');
            }
            $response = Http::timeout(20)->withOptions(['verify' => config('services.integrations.ca_bundle') ?: true])
                ->get($disk->temporaryUrl($path, now()->addMinute()));
            if (! $response->successful() || $response->body() !== $image) {
                throw new RuntimeException('R2 image URL verification failed.');
            }
            $this->info('R2 upload, download and signed image URL: OK.');
        } catch (Throwable) {
            $success = false;
            $this->error('R2 live check failed. Verify bucket access and endpoint settings.');
        } finally {
            if ($created) {
                try {
                    if (! $disk->delete($path)) {
                        throw new RuntimeException('Cleanup failed.');
                    }
                } catch (Throwable) {
                    $success = false;
                    $this->error('R2 verification image cleanup failed.');
                }
            }
        }

        try {
            $gemini->generate('Reply with OK only.');
            $this->info('Gemini text generation: OK.');
        } catch (GeminiException $exception) {
            $success = false;
            $this->error('Gemini live check failed'.($exception->providerStatus ? ' (HTTP '.$exception->providerStatus.')' : '').'.');
        } catch (Throwable) {
            $success = false;
            $this->error('Gemini live check failed. Verify the model and API key settings.');
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
