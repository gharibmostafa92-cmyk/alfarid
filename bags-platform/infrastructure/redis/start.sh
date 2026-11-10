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
  # M3: a rotated REDIS_PASSWORD must take effect on restart; the persisted
  # runtime.conf would otherwise keep the old secret and AUTH would fail cluster-wide.
  sed -i '/^requirepass[[:space:]]/d; /^masterauth[[:space:]]/d' runtime.conf
  printf 'requirepass %s\nmasterauth %s\n' "$REDIS_PASSWORD" "$REDIS_PASSWORD" >> runtime.conf
else
  sed -i '/^requirepass[[:space:]]/d; /^sentinel auth-pass[[:space:]]/d' runtime.conf
  printf 'requirepass %s\nsentinel auth-pass mymaster %s\n' "$REDIS_PASSWORD" "$REDIS_PASSWORD" >> runtime.conf
fi
if [ "$1" != sentinel ]; then
  # H-1: a returning ex-master must not boot as master. Ask Sentinel who the master is now;
  # if no Sentinel answers (cold start), keep the persisted role as before.
  self=$(hostname -i 2>/dev/null | awk '{print $1}')
  master=''
  for s in ${SENTINEL_HOSTS:-sentinel1 sentinel2 sentinel3}; do
    master=$(REDISCLI_AUTH="$REDIS_PASSWORD" timeout 2 redis-cli -h "$s" -p 26379 \
      SENTINEL get-master-addr-by-name mymaster 2>/dev/null | head -n1) || master=''
    [ -n "$master" ] && break
  done
  if [ -n "$master" ]; then
    sed -i '/^replicaof[[:space:]]/d; /^slaveof[[:space:]]/d' runtime.conf
    [ "$master" = "$self" ] || echo "replicaof $master 6379" >> runtime.conf
  fi
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
