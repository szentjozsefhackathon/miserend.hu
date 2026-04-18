#!/usr/bin/env bash
#
# Local PHPUnit test runner
# For CI/CD, see .github/workflows/phpunit.yml
#

set -euo pipefail

# Default values
COVERAGE=false
TAG="latest"
RUNTIME=""
EXTRA_ARGS=""

# Auto-detect container runtime
detect_runtime() {
    if command -v podman &> /dev/null; then
        echo "podman"
    elif command -v docker &> /dev/null; then
        echo "docker"
    else
        echo "Error: Neither docker nor podman found" >&2
        exit 1
    fi
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -c|--coverage) COVERAGE=true; shift ;;
        -t|--tag) TAG="$2"; shift 2 ;;
        -r|--runtime) RUNTIME="$2"; shift 2 ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS] [-- PHPUNIT_ARGS]"
            echo ""
            echo "Local PHPUnit test runner using existing coverage image."
            echo ""
            echo "Options:"
            echo "  -c, --coverage   Generate coverage reports"
            echo "  -t, --tag TAG    Image tag (default: $TAG)"
            echo "  -r, --runtime    Container runtime (docker/podman)"
            echo ""
            echo "Examples:"
            echo "  $0                                  # Quick test"
            echo "  $0 --coverage                       # Test with coverage"
            echo "  $0 --tag 2026.4.18                 # Use specific tag"
            echo "  $0 -- --filter MyTestClass          # Run specific tests"
            exit 0
            ;;
        --) shift; EXTRA_ARGS="$@"; break ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

# Auto-detect runtime if not specified
[ -z "$RUNTIME" ] && RUNTIME=$(detect_runtime)

# Get project root
PROJECT_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PROJECT_ROOT"

# Setup temp directory
mkdir -p /tmp/tests/coverage/html
chmod -R 777 /tmp/tests 2>/dev/null || true

# Image to use
IMAGE="ghcr.io/szentjozsefhackathon/miserend.hu-coverage:$TAG"

# Build PHPUnit command
PHPUNIT_ARGS="-c phpunit.xml"
if [ "$COVERAGE" = true ]; then
    PHPUNIT_ARGS="$PHPUNIT_ARGS --coverage-html /miserend/webapp/fajlok/tmp/tests/coverage/html"
    PHPUNIT_ARGS="$PHPUNIT_ARGS --log-junit /miserend/webapp/fajlok/tmp/tests/junit.xml"
    PHPUNIT_ARGS="$PHPUNIT_ARGS --coverage-cobertura /miserend/webapp/fajlok/tmp/tests/coverage/cobertura.xml"
fi
[ -n "$EXTRA_ARGS" ] && PHPUNIT_ARGS="$PHPUNIT_ARGS $EXTRA_ARGS"

# Run tests (matches GitHub Actions workflow)
echo "🧪 Running PHPUnit tests..."
echo "📦 Image: $IMAGE"
set +e
$RUNTIME run --rm \
    -v "$PROJECT_ROOT/webapp:/miserend/webapp" \
    -v /miserend/webapp/vendor \
    -v /tmp/tests:/miserend/webapp/fajlok/tmp/tests \
    -w /miserend/webapp/tests \
    --entrypoint /miserend/webapp/vendor/bin/phpunit \
    "$IMAGE" \
    $PHPUNIT_ARGS

EXIT_CODE=$?
set -e

# Output results
if [ $EXIT_CODE -eq 0 ]; then
    echo "✅ Tests passed!"
    [ "$COVERAGE" = true ] && echo "📊 Coverage: /tmp/tests/coverage/html/index.html"
else
    echo "❌ Tests failed (exit code: $EXIT_CODE)"
fi

exit $EXIT_CODE
