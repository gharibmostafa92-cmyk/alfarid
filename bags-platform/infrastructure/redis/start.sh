#!/bin/sh
set -eu
case "$REDIS_PASSWORD" in *[!A-Za-z0-9_-]*|'') echo 'Use a URL-safe REDIS_PASSWORD' >&2; exit 1;; esac
cd /data
# Promotion rewrites this file; retain each node's role across restarts.
if [ ! -f runtime.conf ]; then
  if [ "$1" = sentinel ]; then
    cat > runtime.conf <<EOF
port 26379
bind 0.0.0.0
sentinel monitor mymaster 172.31.0.31 6379 2
sentinel auth-pass mymaster $REDIS_PASSWORD
sentinel down-after-milliseconds mymaster 3000
sentinel failover-timeout mymaster 10000
sentinel parallel-syncs mymaster 1
requirepass $REDIS_PASSWORD
EOF
  else
    cat > runtime.conf <<EOF
port 6379
bind 0.0.0.0
protected-mode yes
requirepass $REDIS_PASSWORD
masterauth $REDIS_PASSWORD
appendonly yes
appendfsync everysec
maxmemory 512mb
# Shared security counters and locks must never be evicted to admit cache entries.
maxmemory-policy noeviction
EOF
    [ "$1" != replica ] || echo 'replicaof 172.31.0.31 6379' >> runtime.conf
  fi
fi
if [ "$1" != sentinel ]; then
  # Existing volumes retain Redis' rewritten role/replication settings, but
  # must receive policy updates too. Apply this before handing ownership back.
  sed -i '/^maxmemory-policy[[:space:]]/d' runtime.conf
  echo 'maxmemory-policy noeviction' >> runtime.conf
fi
chown -R redis:redis /data
if [ "$1" = sentinel ]; then
  # Stagger failure detection so three co-located Sentinels do not all vote for
  # themselves in the same scheduling slice after an overloaded host pauses.
  case "${SENTINEL_DOWN_AFTER:-3000}" in *[!0-9]*) exit 1;; esac
  sed -i "s/^sentinel down-after-milliseconds mymaster .*/sentinel down-after-milliseconds mymaster ${SENTINEL_DOWN_AFTER:-3000}/" /data/runtime.conf
  exec docker-entrypoint.sh redis-server /data/runtime.conf --sentinel
fi
exec docker-entrypoint.sh redis-server /data/runtime.conf
