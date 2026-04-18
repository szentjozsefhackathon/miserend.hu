#!/bin/sh
set -e

echo "Waiting for Elasticsearch data initialization..."

# Wait until the data directory is populated (data-init has completed)
MAX_WAIT=60
ELAPSED=0
while [ ! -f /usr/share/elasticsearch/data/nodes/0/_state/node-*.st ] && [ $ELAPSED -lt $MAX_WAIT ]; do
  if [ -d /usr/share/elasticsearch/data/nodes ]; then
    echo "Data directory structure found, proceeding..."
    break
  fi
  echo "Waiting for data initialization... ($ELAPSED seconds)"
  sleep 2
  ELAPSED=$((ELAPSED + 2))
done

if [ $ELAPSED -ge $MAX_WAIT ]; then
  echo "Warning: Timeout waiting for data initialization, starting anyway..."
fi

echo "Starting Elasticsearch..."
# Execute the original Elasticsearch entrypoint
exec /bin/tini -- /usr/local/bin/docker-entrypoint.sh "$@"
