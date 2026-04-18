#!/usr/bin/env bash

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
COVERAGE=false
TAG="2026.4.18"
CONTAINER_RUNTIME=""
EXTRA_ARGS=""

# Function to print usage
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  -c, --coverage          Run tests with coverage reports"
    echo "  -t, --tag TAG           Docker/Podman image tag (default: 2026.4.18)"
    echo "  -r, --runtime RUNTIME   Container runtime: docker or podman (auto-detected if not specified)"
    echo "  -h, --help              Show this help message"
    echo "  -- [ARGS]               Additional arguments to pass to phpunit"
    echo ""
    echo "Examples:"
    echo "  $0                                    # Run basic tests"
    echo "  $0 --coverage                         # Run tests with coverage"
    echo "  $0 --tag 2026.4.17                   # Run tests with specific image tag"
    echo "  $0 --runtime podman --coverage       # Force podman with coverage"
    echo "  $0 -- --filter MyTestClass           # Pass additional phpunit arguments"
    exit 1
}

# Auto-detect container runtime
detect_runtime() {
    if command -v podman &> /dev/null; then
        echo "podman"
    elif command -v docker &> /dev/null; then
        echo "docker"
    else
        echo -e "${RED}Error: Neither docker nor podman found${NC}" >&2
        exit 1
    fi
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -c|--coverage)
            COVERAGE=true
            shift
            ;;
        -t|--tag)
            TAG="$2"
            shift 2
            ;;
        -r|--runtime)
            CONTAINER_RUNTIME="$2"
            shift 2
            ;;
        -h|--help)
            usage
            ;;
        --)
            shift
            EXTRA_ARGS="$@"
            break
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            usage
            ;;
    esac
done

# Auto-detect runtime if not specified
if [ -z "$CONTAINER_RUNTIME" ]; then
    CONTAINER_RUNTIME=$(detect_runtime)
fi

# Validate runtime
if [ "$CONTAINER_RUNTIME" != "docker" ] && [ "$CONTAINER_RUNTIME" != "podman" ]; then
    echo -e "${RED}Error: Invalid runtime '$CONTAINER_RUNTIME'. Must be 'docker' or 'podman'${NC}" >&2
    exit 1
fi

# Verify runtime is available
if ! command -v "$CONTAINER_RUNTIME" &> /dev/null; then
    echo -e "${RED}Error: $CONTAINER_RUNTIME is not available${NC}" >&2
    exit 1
fi

echo -e "${GREEN}Using container runtime: $CONTAINER_RUNTIME${NC}"
echo -e "${GREEN}Image tag: $TAG${NC}"
echo -e "${GREEN}Coverage: $COVERAGE${NC}"

# Always use the coverage image as phpunit is only available there
IMAGE="ghcr.io/szentjozsefhackathon/miserend.hu-coverage:$TAG"

# Get the script directory and project root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

# Determine temp directory (cross-platform)
if [[ "$OSTYPE" == "msys" || "$OSTYPE" == "win32" ]]; then
    # Windows
    TEMP_DIR="${TEMP:-/tmp}/tests"
else
    # Linux/Unix
    TEMP_DIR="/tmp/tests"
fi

# Create output directories with proper permissions
mkdir -p "$TEMP_DIR/coverage/html"
chmod -R 777 "$TEMP_DIR" 2>/dev/null || true

# Build phpunit command
PHPUNIT_CMD="/miserend/webapp/vendor/bin/phpunit -c phpunit.xml"

if [ "$COVERAGE" = true ]; then
    PHPUNIT_CMD="$PHPUNIT_CMD --coverage-html /miserend/webapp/fajlok/tmp/tests/coverage/html"
    PHPUNIT_CMD="$PHPUNIT_CMD --log-junit /miserend/webapp/fajlok/tmp/tests/junit.xml"
    PHPUNIT_CMD="$PHPUNIT_CMD --coverage-cobertura /miserend/webapp/fajlok/tmp/tests/coverage/cobertura.xml"
fi

# Add extra arguments if provided
if [ -n "$EXTRA_ARGS" ]; then
    PHPUNIT_CMD="$PHPUNIT_CMD $EXTRA_ARGS"
fi

# Container name
CONTAINER_NAME="miserend_test_$$"

# Cleanup function
cleanup() {
    echo -e "${YELLOW}Cleaning up container...${NC}"
    $CONTAINER_RUNTIME stop "$CONTAINER_NAME" 2>/dev/null || true
    $CONTAINER_RUNTIME rm "$CONTAINER_NAME" 2>/dev/null || true
}

# Set trap for cleanup
trap cleanup EXIT INT TERM

# Run the tests
echo -e "${GREEN}Running tests...${NC}"
$CONTAINER_RUNTIME run \
    -t \
    -v "$PROJECT_ROOT/webapp/tests:/miserend/webapp/tests" \
    -v "$PROJECT_ROOT/webapp/classes:/miserend/webapp/classes" \
    -v "$TEMP_DIR:/miserend/webapp/fajlok/tmp/tests" \
    --name "$CONTAINER_NAME" \
    -w /miserend/webapp/tests \
    --entrypoint /miserend/webapp/vendor/bin/phpunit \
    "$IMAGE" \
    -c phpunit.xml \
    $([ "$COVERAGE" = true ] && echo "--coverage-html /miserend/webapp/fajlok/tmp/tests/coverage/html --log-junit /miserend/webapp/fajlok/tmp/tests/junit.xml --coverage-cobertura /miserend/webapp/fajlok/tmp/tests/coverage/cobertura.xml") \
    $EXTRA_ARGS

# Capture exit code
EXIT_CODE=$?

# Manual cleanup (trap will also run but it's safe)
cleanup

if [ $EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}Tests passed!${NC}"
    if [ "$COVERAGE" = true ]; then
        echo -e "${GREEN}Coverage report available at: $TEMP_DIR/coverage/html/index.html${NC}"
    fi
else
    echo -e "${RED}Tests failed with exit code $EXIT_CODE${NC}"
fi

exit $EXIT_CODE
