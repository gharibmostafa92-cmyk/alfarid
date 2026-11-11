<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::unprepared(<<<'SQL'
            CREATE TEMPORARY TABLE customer_channel_phone_backfill ON COMMIT DROP AS
            SELECT id, provider, CASE
                WHEN compact ~ '^01[0125][0-9]{8}$' THEN '+2' || compact
                WHEN compact ~ '^201[0125][0-9]{8}$' THEN '+' || compact
                WHEN compact ~ '^00201[0125][0-9]{8}$' THEN '+' || substr(compact, 3)
                ELSE compact
            END AS phone
            FROM (
                SELECT id, provider, regexp_replace(
                    translate(phone, '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'),
                    '[[:space:]().-]+', '', 'g'
                ) AS compact
                FROM customer_channels
            ) AS normalized;

            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customer_channel_phone_backfill WHERE phone !~ '^\+[1-9][0-9]{1,14}$') THEN
                    RAISE EXCEPTION 'Resolve invalid customer channel phone numbers before migration';
                END IF;
                IF EXISTS (SELECT 1 FROM customer_channel_phone_backfill GROUP BY provider, phone HAVING count(*) > 1) THEN
                    RAISE EXCEPTION 'Resolve duplicate customer channel phone owners before migration';
                END IF;
            END;
            $$;

            ALTER TABLE customer_channels
                ADD COLUMN verification_attempts smallint NOT NULL DEFAULT 0,
                ADD CONSTRAINT customer_channels_otp_attempts_check CHECK (verification_attempts BETWEEN 0 AND 5);

            UPDATE customer_channels c SET phone = b.phone, verification_code_hash = NULL, verification_expires_at = NULL
            FROM customer_channel_phone_backfill b WHERE c.id = b.id;

            ALTER TABLE customer_channels ADD CONSTRAINT customer_channels_e164_check CHECK (phone ~ '^\+[1-9][0-9]{1,14}$');
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer_channels DROP CONSTRAINT customer_channels_e164_check, DROP CONSTRAINT customer_channels_otp_attempts_check, DROP COLUMN verification_attempts');
    }
};
