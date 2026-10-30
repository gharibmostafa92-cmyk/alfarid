-- Run only against an isolated PostgreSQL instance, never the production server.
-- Example: psql -v rows=6000000 -f notifications-scale.sql
-- All objects are temporary and disappear at COMMIT or connection termination.
\set ON_ERROR_STOP on
\timing on
\if :{?rows}
\else
\set rows 6000000
\endif
BEGIN;
SET LOCAL statement_timeout = '0';
SET LOCAL work_mem = '32MB';
CREATE TEMPORARY TABLE notifications (
    id uuid PRIMARY KEY,
    type text NOT NULL,
    notifiable_type text NOT NULL,
    notifiable_id bigint NOT NULL,
    data text NOT NULL,
    read_at timestamp,
    created_at timestamp NOT NULL,
    updated_at timestamp NOT NULL
) ON COMMIT DROP;
INSERT INTO notifications
SELECT md5(i::text)::uuid, 'OrderCreated', 'App\Models\User', ((i - 1) % 20000) + 1,
       repeat('x', 200), CASE WHEN (i / 20000) % 5 <> 0 THEN now() ELSE NULL END,
       now() - ((i - 1) / 200000) * interval '1 day' - (i % 86400) * interval '1 second', now()
FROM generate_series(1, :rows) AS series(i);
CREATE INDEX notifications_recipient_created_idx ON notifications (notifiable_type, notifiable_id, created_at DESC);
CREATE INDEX notifications_unread_recipient_created_idx ON notifications (notifiable_type, notifiable_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_created_at_idx ON notifications (created_at);
ANALYZE notifications;
SELECT count(*) AS generated_rows, pg_size_pretty(pg_total_relation_size('notifications')) AS total_size FROM notifications;
EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM notifications WHERE notifiable_type = 'App\Models\User' AND notifiable_id = 42 ORDER BY created_at DESC LIMIT 20;
EXPLAIN (ANALYZE, BUFFERS)
SELECT count(*) FROM notifications WHERE notifiable_type = 'App\Models\User' AND notifiable_id = 42 AND read_at IS NULL;
EXPLAIN (ANALYZE, BUFFERS)
SELECT id FROM notifications WHERE read_at IS NOT NULL AND created_at < now() - interval '14 days' ORDER BY created_at, id LIMIT 1000;
COMMIT;
