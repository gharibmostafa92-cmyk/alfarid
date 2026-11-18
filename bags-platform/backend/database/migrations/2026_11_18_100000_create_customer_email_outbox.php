<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_email_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 255)->unique();
            $table->text('payload')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('first_attempt_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->string('provider_id', 191)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE customer_email_outbox ADD CONSTRAINT customer_email_outbox_status_check CHECK (status IN ('pending', 'sending', 'accepted', 'failed', 'review'))");
            DB::statement('ALTER TABLE customer_email_outbox ADD CONSTRAINT customer_email_outbox_attempts_check CHECK (attempts BETWEEN 0 AND 8)');
            DB::statement("ALTER TABLE customer_email_outbox ADD CONSTRAINT customer_email_outbox_lease_check CHECK ((status = 'sending' AND lease_token IS NOT NULL) OR (status <> 'sending' AND lease_token IS NULL))");
            DB::statement("ALTER TABLE customer_email_outbox ADD CONSTRAINT customer_email_outbox_provider_check CHECK ((status = 'accepted' AND provider_id IS NOT NULL) OR (status <> 'accepted'))");
            DB::statement("ALTER TABLE customer_email_outbox ADD CONSTRAINT customer_email_outbox_payload_check CHECK ((status IN ('accepted', 'failed') AND payload IS NULL) OR (status NOT IN ('accepted', 'failed') AND payload IS NOT NULL))");
            DB::statement("CREATE INDEX customer_email_outbox_due_idx ON customer_email_outbox (available_at, id) WHERE status IN ('pending', 'sending')");
            DB::statement("CREATE INDEX customer_email_outbox_review_cleanup_idx ON customer_email_outbox (updated_at, id) WHERE status = 'review'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_email_outbox');
    }
};
