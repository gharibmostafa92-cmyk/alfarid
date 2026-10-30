-- Isolated PostgreSQL benchmark of the sweeper's 500-path JSON batch lookup.
-- Temporary data is discarded when this connection closes.
\set ON_ERROR_STOP on
\timing on
\if :{?use_index}
\else
\set use_index true
\endif
BEGIN;
SET LOCAL statement_timeout = '0';
CREATE TEMPORARY TABLE return_request_items (id bigint PRIMARY KEY, attachments json) ON COMMIT DROP;
INSERT INTO return_request_items
SELECT i, json_build_array('return_requests/' || i || '/proof.jpg') FROM generate_series(1, 100000) AS series(i);
\if :use_index
CREATE INDEX return_request_items_attachments_gin_idx ON return_request_items USING gin ((attachments::jsonb));
\endif
ANALYZE return_request_items;
DO $$
DECLARE
    predicate text;
    plan json;
BEGIN
    SELECT string_agg(format('attachments::jsonb @> %L::jsonb', to_json('return_requests/' || i || '/proof.jpg')::text), ' OR ')
    INTO predicate FROM generate_series(1, 500) AS series(i);
    EXECUTE 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT attachments FROM return_request_items WHERE ' || predicate INTO plan;
    RAISE NOTICE 'rows=100000, candidate_paths=500, plan=%, matched=%, execution_ms=%, planning_ms=%',
        plan->0->'Plan'->>'Node Type', plan->0->'Plan'->>'Actual Rows', plan->0->>'Execution Time', plan->0->>'Planning Time';
    IF plan::text NOT LIKE '%return_request_items_attachments_gin_idx%' THEN
        RAISE NOTICE 'The lookup did not use the attachment GIN index.';
    END IF;
END $$;
COMMIT;
