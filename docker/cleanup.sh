#!/bin/bash

# Miserend.hu cleanup script
# Stops containers and removes volumes for the miserend development environment

set -e

# Detect container runtime
if command -v podman &> /dev/null; then
    RUNTIME="podman"
    echo "✓ Using Podman"
elif command -v docker &> /dev/null; then
    RUNTIME="docker"
    echo "✓ Using Docker"
else
    echo "✗ Neither Podman nor Docker found!"
    exit 1
fi

echo ""
echo "Cleaning up miserend.hu environment..."
echo ""

# Miserend-specific containers
CONTAINERS=(
    "docker_tmp-init_1"
    "docker_data-init_1"
    "mailcatcher"
    "miserend"
    "mysql"
    "elasticsearch"
)

# Stop and remove containers
for container in "${CONTAINERS[@]}"; do
    if $RUNTIME ps -a --format "{{.Names}}" | grep -q "^${container}$"; then
        echo "  Stopping and removing container: $container"
        $RUNTIME stop "$container" 2>/dev/null || true
        $RUNTIME rm "$container" 2>/dev/null || true
    fi
done

echo ""

# Miserend-specific volumes
VOLUMES=(
    "docker_tmp"
    "docker_elasticsearch_data"
    "docker_db_data"
)

# Remove volumes
for volume in "${VOLUMES[@]}"; do
    if $RUNTIME volume ls --format "{{.Name}}" | grep -q "^${volume}$"; then
        echo "  Removing volume: $volume"
        $RUNTIME volume rm "$volume" 2>/dev/null || true
    fi
done

echo ""
echo "✓ Cleanup complete!"
