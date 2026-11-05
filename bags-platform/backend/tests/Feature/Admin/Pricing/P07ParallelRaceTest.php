<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use Tests\TestCase;

/**
 * task.txt items 2, 7, 8a, 10, 28b, 29-concurrent plus the 409 lock path
 * and same-tier races — executed as separate OS processes against a real
 * PostgreSQL database (alfarid_testing_race), never as sequential
 * simulations. The race database is created once and migrated fresh once
 * for the whole file; every fixture uses unique codes/ids.
 */
class P07ParallelRaceTest extends TestCase
{
    private static bool $fresh = false;

    private static string $raceDb = 'alfarid_testing_race';

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$fresh) {
            $this->ensureRaceDatabase();
            $out = $this->runWorker(['job' => 'migrate']);
            $this->assertTrue($out['ok'] ?? false, 'race database migration failed: '.json_encode($out));
            self::$fresh = true;
        }
    }

    private function ensureRaceDatabase(): void
    {
        $cfg = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname=postgres", $cfg['username'], $cfg['password']);
        $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '".self::$raceDb."'")->fetchColumn();
        if (! $exists) {
            $pdo->exec('CREATE DATABASE "'.self::$raceDb.'"');
        }
    }

    private function basePath(): string
    {
        return base_path();
    }

    /** @return resource */
    private function spawnWorker(array $payload, string $dir)
    {
        $file = $dir.'/payload_'.uniqid().'.json';
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $env = array_merge(getenv(), ['DB_DATABASE' => self::$raceDb]);
        $cmd = PHP_BINARY.' artisan race:worker --file='.$file;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->basePath(), $env);
        $this->assertIsResource($proc, 'could not spawn race worker');
        fclose($pipes[0]);

        return [$proc, $pipes];
    }

    /** @return array{proc:resource, pipes:array, payload:array} */
    private function start(array $payload, string $dir): array
    {
        [$proc, $pipes] = $this->spawnWorker($payload, $dir);

        return ['proc' => $proc, 'pipes' => $pipes, 'payload' => $payload];
    }

    private function collect(array $handle, int $timeoutSeconds = 90): array
    {
        $out = '';
        $err = '';
        $start = microtime(true);
        stream_set_blocking($handle['pipes'][1], false);
        stream_set_blocking($handle['pipes'][2], false);
        while (true) {
            $read = [$handle['pipes'][1], $handle['pipes'][2]];
            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, 1);
            if ($ready !== false) {
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($stream === $handle['pipes'][1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }
            $status = proc_get_status($handle['proc']);
            if (! $status['running']) {
                $out .= stream_get_contents($handle['pipes'][1]);
                $err .= stream_get_contents($handle['pipes'][2]);
                break;
            }
            if (microtime(true) - $start > $timeoutSeconds) {
                proc_terminate($handle['proc']);
                $this->fail('race worker timed out: '.json_encode($handle['payload']).' stderr: '.substr($err, 0, 500));
            }
        }
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        proc_close($handle['proc']);
        foreach (explode("\n", trim($out)) as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $this->fail('race worker returned no JSON. stdout: '.substr($out, 0, 500).' stderr: '.substr($err, 0, 500));

        return [];
    }

    private function runWorker(array $payload, int $timeoutSeconds = 120): array
    {
        $dir = sys_get_temp_dir().'/race_'.uniqid();
        mkdir($dir);

        return $this->collect($this->start($payload, $dir), $timeoutSeconds);
    }

    private function raceDir(): string
    {
        $dir = sys_get_temp_dir().'/race_'.uniqid();
        mkdir($dir);

        return $dir;
    }

    // ── item 2: two separate processes confirm the same card; exactly one executes ──
    public function test_parallel_confirms_execute_exactly_once(): void
    {
        $dir = $this->raceDir();
        $setup = $this->runWorker(['job' => 'setup_bulk']);
        $this->assertTrue($setup['ok']);

        $go = $dir.'/go';
        $a = $this->start(['job' => 'confirm', 'action_id' => $setup['action_id'], 'user_id' => $setup['user_id'], 'typed' => 'تنفيذ', 'wait_go' => $go], $dir);
        $b = $this->start(['job' => 'confirm', 'action_id' => $setup['action_id'], 'user_id' => $setup['user_id'], 'typed' => 'تنفيذ', 'wait_go' => $go], $dir);
        touch($go);
        $ra = $this->collect($a);
        $rb = $this->collect($b);

        $this->assertTrue($ra['ok'] ?? false, json_encode($ra, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($rb['ok'] ?? false, json_encode($rb, JSON_UNESCAPED_UNICODE));
        $this->assertSame('executed', $this->runWorker(['job' => 'inspect', 'table' => 'ai_pending_actions', 'id' => $setup['action_id']])['row']['status']);

        // Exactly one execution: both variants moved 400 → 440 with a single version bump each.
        foreach ($setup['variant_ids'] as $variantId) {
            $row = $this->runWorker(['job' => 'inspect', 'table' => 'product_variants', 'id' => $variantId])['row'];
            $this->assertSame(440.0, (float) $row['retail_price']);
        }
        $versions = [];
        foreach ($setup['variant_ids'] as $variantId) {
            $versions[] = $this->runWorker(['job' => 'inspect', 'table' => 'product_variants', 'id' => $variantId])['row']['lock_version'];
        }
        $this->assertSame($versions[0], $versions[1]);
    }

    // ── item 7: two processes redeem the last global slot; exactly one wins ──
    public function test_parallel_global_redemption_has_single_winner(): void
    {
        foreach ([true, false] as $inTransaction) {
            $dir = $this->raceDir();
            $code = 'RACE'.strtoupper(uniqid());
            $setup = $this->runWorker(['job' => 'coupon_setup', 'code' => $code, 'usage_limit' => 1,
                'orders' => ['01090000001', '01090000002']]);
            $this->assertTrue($setup['ok']);

            $go = $dir.'/go';
            $a = $this->start(['job' => 'coupon_record', 'coupon_id' => $setup['coupon_id'], 'order_id' => $setup['order_ids'][0],
                'discount' => 10, 'in_transaction' => $inTransaction, 'wait_go' => $go], $dir);
            $b = $this->start(['job' => 'coupon_record', 'coupon_id' => $setup['coupon_id'], 'order_id' => $setup['order_ids'][1],
                'discount' => 10, 'in_transaction' => $inTransaction, 'wait_go' => $go], $dir);
            touch($go);
            $ra = $this->collect($a);
            $rb = $this->collect($b);

            $wins = (int) ($ra['ok'] ?? false) + (int) ($rb['ok'] ?? false);
            $this->assertSame(1, $wins, 'in_transaction='.var_export($inTransaction, true).' A='.json_encode($ra).' B='.json_encode($rb));
            $this->assertSame(1, $this->runWorker(['job' => 'count', 'table' => 'coupon_usages', 'where' => [['coupon_id', $setup['coupon_id']]]])['count']);
        }
    }

    // ── item 8a: two processes redeem for the same guest; per-customer cap holds ──
    public function test_parallel_same_guest_redemption_has_single_winner(): void
    {
        $code = 'RACEG'.strtoupper(uniqid());
        $setup = $this->runWorker(['job' => 'coupon_setup', 'code' => $code, 'usage_limit' => null,
            'per_customer' => 1, 'orders' => ['01090000003', '01090000003']]);
        $this->assertTrue($setup['ok']);

        $dir = $this->raceDir();
        $go = $dir.'/go';
        $a = $this->start(['job' => 'coupon_record', 'coupon_id' => $setup['coupon_id'], 'order_id' => $setup['order_ids'][0], 'discount' => 5, 'wait_go' => $go], $dir);
        $b = $this->start(['job' => 'coupon_record', 'coupon_id' => $setup['coupon_id'], 'order_id' => $setup['order_ids'][1], 'discount' => 5, 'wait_go' => $go], $dir);
        touch($go);
        $ra = $this->collect($a);
        $rb = $this->collect($b);

        $this->assertSame(1, (int) ($ra['ok'] ?? false) + (int) ($rb['ok'] ?? false));
        $this->assertSame(1, $this->runWorker(['job' => 'count', 'table' => 'coupon_usages', 'where' => [['coupon_id', $setup['coupon_id']]]])['count']);
    }

    // ── item 10: delete vs usage race never loses a usage row nor 500s ──
    public function test_parallel_delete_and_usage_stay_consistent(): void
    {
        // Sequential interleavings first (deterministic both directions).
        $code = 'RACED'.strtoupper(uniqid());
        $setup = $this->runWorker(['job' => 'coupon_setup', 'code' => $code, 'usage_limit' => null,
            'per_customer' => 5, 'orders' => ['01090000004']]);
        $destroy = $this->runWorker(['job' => 'coupon_destroy', 'coupon_id' => $setup['coupon_id']]);
        $this->assertTrue($destroy['ok']);
        $afterDelete = $this->runWorker(['job' => 'coupon_record', 'coupon_id' => $setup['coupon_id'], 'order_id' => $setup['order_ids'][0], 'discount' => 4]);
        $this->assertFalse($afterDelete['ok']);

        $code2 = 'RACED'.strtoupper(uniqid());
        $setup2 = $this->runWorker(['job' => 'coupon_setup', 'code' => $code2, 'usage_limit' => null,
            'per_customer' => 5, 'orders' => ['01090000005']]);
        $record = $this->runWorker(['job' => 'coupon_record', 'coupon_id' => $setup2['coupon_id'], 'order_id' => $setup2['order_ids'][0], 'discount' => 4]);
        $this->assertTrue($record['ok']);
        $refused = $this->runWorker(['job' => 'coupon_destroy', 'coupon_id' => $setup2['coupon_id']]);
        $this->assertFalse($refused['ok']);
        $this->assertSame(422, $refused['http']);

        // True parallel run: usage-first ordering forced by delaying the delete.
        $code3 = 'RACED'.strtoupper(uniqid());
        $setup3 = $this->runWorker(['job' => 'coupon_setup', 'code' => $code3, 'usage_limit' => null,
            'per_customer' => 5, 'orders' => ['01090000006']]);
        $dir = $this->raceDir();
        $go = $dir.'/go';
        $a = $this->start(['job' => 'coupon_destroy', 'coupon_id' => $setup3['coupon_id'], 'sleep_ms' => 600, 'wait_go' => $go], $dir);
        $b = $this->start(['job' => 'coupon_record', 'coupon_id' => $setup3['coupon_id'], 'order_id' => $setup3['order_ids'][0], 'discount' => 4, 'wait_go' => $go], $dir);
        touch($go);
        $ra = $this->collect($a);
        $rb = $this->collect($b);
        $this->assertTrue($rb['ok'] ?? false, json_encode($rb));
        $this->assertFalse($ra['ok'] ?? true, json_encode($ra));
        $this->assertNotSame(500, $ra['http'] ?? 500);
        $this->assertSame(1, $this->runWorker(['job' => 'count', 'table' => 'coupon_usages', 'where' => [['coupon_id', $setup3['coupon_id']]]])['count']);

        // Free race: either side may win, but state stays consistent and no 500 occurs.
        $code4 = 'RACED'.strtoupper(uniqid());
        $setup4 = $this->runWorker(['job' => 'coupon_setup', 'code' => $code4, 'usage_limit' => null,
            'per_customer' => 5, 'orders' => ['01090000007']]);
        $dir = $this->raceDir();
        $go = $dir.'/go';
        $a = $this->start(['job' => 'coupon_destroy', 'coupon_id' => $setup4['coupon_id'], 'wait_go' => $go], $dir);
        $b = $this->start(['job' => 'coupon_record', 'coupon_id' => $setup4['coupon_id'], 'order_id' => $setup4['order_ids'][0], 'discount' => 4, 'wait_go' => $go], $dir);
        touch($go);
        $ra = $this->collect($a);
        $rb = $this->collect($b);
        $this->assertNotSame(500, $ra['http'] ?? 200, json_encode($ra));
        $couponRow = $this->runWorker(['job' => 'inspect', 'table' => 'coupons', 'id' => $setup4['coupon_id']]);
        $usageCount = $this->runWorker(['job' => 'count', 'table' => 'coupon_usages', 'where' => [['coupon_id', $setup4['coupon_id']]]])['count'];
        if ($ra['ok'] ?? false) {
            $this->assertFalse($couponRow['ok']);
            $this->assertSame(0, $usageCount);
            $this->assertFalse($rb['ok'] ?? true);
        } else {
            $this->assertTrue($couponRow['ok']);
            $this->assertSame(1, $usageCount);
        }
    }

    // ── item 28b: concurrent creates with the same code → one 201, one 422 ──
    public function test_parallel_duplicate_coupon_create_has_single_winner(): void
    {
        $code = 'RACEC'.strtoupper(uniqid());
        $payload = ['code' => $code, 'type' => 'percentage', 'value' => 10];
        $dir = $this->raceDir();
        $go = $dir.'/go';
        $a = $this->start(['job' => 'coupon_store', 'payload' => $payload, 'wait_go' => $go], $dir);
        $b = $this->start(['job' => 'coupon_store', 'payload' => $payload, 'wait_go' => $go], $dir);
        touch($go);
        $ra = $this->collect($a);
        $rb = $this->collect($b);

        $codes = [$ra['http'] ?? 0, $rb['http'] ?? 0];
        sort($codes);
        $this->assertSame([201, 422], $codes, 'A='.json_encode($ra).' B='.json_encode($rb));
        $this->assertSame(1, $this->runWorker(['job' => 'count', 'table' => 'coupons', 'where' => [['code', $code]]])['count']);
    }

    // ── item 29: delete vs attach race — no silent null, no 500 ──
    public function test_parallel_group_delete_and_attach_stay_consistent(): void
    {
        // Sequential interleavings first (deterministic both directions).
        $setup = $this->runWorker(['job' => 'group_setup']);
        $attach = $this->runWorker(['job' => 'customer_attach', 'customer_id' => $setup['customer_id'], 'group_id' => $setup['group_id']]);
        $this->assertTrue($attach['ok']);
        $refused = $this->runWorker(['job' => 'group_delete', 'group_id' => $setup['group_id'], 'actor_id' => $setup['actor_id']]);
        $this->assertFalse($refused['ok']);

        $setup2 = $this->runWorker(['job' => 'group_setup']);
        $deleted = $this->runWorker(['job' => 'group_delete', 'group_id' => $setup2['group_id'], 'actor_id' => $setup2['actor_id']]);
        $this->assertTrue($deleted['ok']);
        $late = $this->runWorker(['job' => 'customer_attach', 'customer_id' => $setup2['customer_id'], 'group_id' => $setup2['group_id']]);
        $this->assertFalse($late['ok']);
        $this->assertStringContainsString('23503', (string) ($late['message'] ?? ''));
        $customer = $this->runWorker(['job' => 'inspect', 'table' => 'customers', 'id' => $setup2['customer_id']]);
        $this->assertNull($customer['row']['customer_group_id']);

        // True parallel run: invariants hold whichever side wins.
        $setup3 = $this->runWorker(['job' => 'group_setup']);
        $dir = $this->raceDir();
        $go = $dir.'/go';
        $a = $this->start(['job' => 'group_delete', 'group_id' => $setup3['group_id'], 'actor_id' => $setup3['actor_id'], 'wait_go' => $go], $dir);
        $b = $this->start(['job' => 'customer_attach', 'customer_id' => $setup3['customer_id'], 'group_id' => $setup3['group_id'], 'wait_go' => $go], $dir);
        touch($go);
        $ra = $this->collect($a);
        $rb = $this->collect($b);
        $group = $this->runWorker(['job' => 'inspect', 'table' => 'customer_groups', 'id' => $setup3['group_id']]);
        $customer = $this->runWorker(['job' => 'inspect', 'table' => 'customers', 'id' => $setup3['customer_id']]);
        if ($ra['ok'] ?? false) {
            $this->assertFalse($group['ok']);
            $this->assertNull($customer['row']['customer_group_id']);
        } else {
            $this->assertTrue($group['ok']);
            $this->assertSame($setup3['group_id'], (int) $customer['row']['customer_group_id']);
        }
    }

    // ── section 3: same-tier parallel creates → one 201, one 422, never 500 ──
    public function test_parallel_duplicate_tier_create_has_single_winner(): void
    {
        $setup = $this->runWorker(['job' => 'tier_setup']);
        $dir = $this->raceDir();
        $go = $dir.'/go';
        $args = ['job' => 'tier_create', 'staff_id' => $setup['staff_id'], 'list_id' => $setup['list_id'],
            'variant_id' => $setup['variant_id'], 'min_qty' => 5, 'price' => 77, 'wait_go' => $go];
        $a = $this->start($args, $dir);
        $b = $this->start($args, $dir);
        touch($go);
        $ra = $this->collect($a);
        $rb = $this->collect($b);

        $this->assertTrue(($ra['ok'] ?? false) xor ($rb['ok'] ?? false), 'A='.json_encode($ra).' B='.json_encode($rb));
        $loser = ($ra['ok'] ?? false) ? $rb : $ra;
        $this->assertSame(422, $loser['http'] ?? 0);
        $this->assertSame(1, $this->runWorker(['job' => 'count', 'table' => 'price_list_items', 'where' => [['price_list_id', $setup['list_id']]]])['count']);
    }

    // ── section 3: a busy global admin lock turns bulk confirm into 409-pending ──
    public function test_busy_admin_lock_returns_409_and_keeps_pending(): void
    {
        $dir = $this->raceDir();
        $setup = $this->runWorker(['job' => 'setup_bulk']);
        $ready = $dir.'/holder_ready';
        $holder = $this->start(['job' => 'advisory_hold', 'ready_file' => $ready, 'seconds' => 9], $dir);

        $start = microtime(true);
        while (! file_exists($ready) && microtime(true) - $start < 30) {
            usleep(100000);
        }
        $this->assertFileExists($ready, 'lock holder never acquired the advisory lock');

        $attempt = $this->runWorker(['job' => 'bulk_confirm', 'action_id' => $setup['parent_id'],
            'user_id' => $setup['user_id'], 'typed' => 'تنفيذ', 'selected' => $setup['child_ids']], 60);
        $this->assertSame(409, $attempt['http'] ?? 0, json_encode($attempt));
        $this->assertSame('pending', $this->runWorker(['job' => 'inspect', 'table' => 'ai_pending_actions', 'id' => $setup['parent_id']])['row']['status']);
        $this->collect($holder, 30);
    }
}
