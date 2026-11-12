<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Services\Ai\WhatsApp\WhatsAppGatewayClient;
use App\Services\Ai\WhatsApp\WhatsAppGatewayException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class WatchWhatsAppConnection extends Command
{
    protected $signature = 'whatsapp:watch-connection';

    protected $description = 'Alert when the WhatsApp gateway stays disconnected';

    public function handle(WhatsAppGatewayClient $gateway): int
    {
        if (blank(config('services.whatsapp_gateway.url')) || blank(config('services.whatsapp_gateway.secret'))) {
            return self::SUCCESS;
        }

        try {
            $status = $gateway->status();
        } catch (WhatsAppGatewayException) {
            $status = ['state' => 'unreachable'];
        }
        $state = (string) ($status['state'] ?? 'unreachable');
        if ($state === 'connected') {
            Cache::forget('wa-gateway-alerted');
            Cache::forget('wa-gateway-disconnected-since');

            return self::SUCCESS;
        }
        $started = Cache::add('wa-gateway-disconnected-since', now()->toIso8601String(), now()->addDays(7));
        $episodes = 'wa-gateway-disconnect-episodes';
        if ($started) {
            RateLimiter::hit($episodes, 1800);
        }

        $since = Cache::get('wa-gateway-disconnected-since');
        $longClose = is_string($since) && Carbon::parse($since)->lte(now()->subMinutes(10));
        $repeated = RateLimiter::attempts($episodes) >= 3;
        if ($state !== 'logged_out' && ! $longClose && ! $repeated) {
            return self::SUCCESS;
        }
        $alertKey = $repeated ? 'wa-gateway-flapping-alerted' : 'wa-gateway-alerted';
        if (! Cache::add($alertKey, $state, now()->addHours(6))) {
            return self::SUCCESS;
        }
        $context = ['state' => $state, 'repeated_disconnects' => $repeated];
        if (filled(config('logging.channels.slack.url'))) {
            Log::channel('slack')->critical('اتصال واتساب واقع.', $context);
        } else {
            Log::warning('اتصال واتساب واقع.', $context);
        }

        return self::SUCCESS;
    }
}
