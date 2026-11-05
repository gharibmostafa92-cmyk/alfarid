<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\WhatsApp\WhatsAppGatewayClient;
use App\Services\Ai\WhatsApp\WhatsAppGatewayException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
        $since = Cache::remember('wa-gateway-disconnected-since', now()->addDay(), fn () => now()->toIso8601String());
        $longClose = in_array($state, ['close', 'reconnecting', 'unreachable'], true)
            && Carbon::parse($since)->lte(now()->subMinutes(10));
        if ($state !== 'logged_out' && ! $longClose) {
            return self::SUCCESS;
        }
        if (! Cache::add('wa-gateway-alerted', $state, now()->addHours(6))) {
            return self::SUCCESS;
        }
        if (filled(config('logging.channels.slack.url'))) {
            Log::channel('slack')->critical('اتصال واتساب واقع.', ['state' => $state]);
        } else {
            Log::warning('اتصال واتساب واقع.', ['state' => $state]);
        }

        return self::SUCCESS;
    }
}
