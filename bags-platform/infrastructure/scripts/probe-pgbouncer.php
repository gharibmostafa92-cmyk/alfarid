<?php

// Run with the application's PDO driver through PgBouncer, before/after changing
// DB_DISABLE_PREPARES. No permanent schema or business data is written.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
$pdo->beginTransaction();
try {
    for ($i = 0; $i < 5; $i++) {
        $statement = $pdo->prepare('select cast(? as integer) as probe');
        $statement->execute([$i]);
        if ((int) $statement->fetchColumn() !== $i) {
            throw new RuntimeException('Bound parameter changed');
        }
        unset($statement); // PHP 8.3 named-statement cleanup aborts transactions.
    }
    $pdo->commit();
    echo "PASS: bound parameters and statement cleanup preserve the transaction\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
