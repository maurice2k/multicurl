# Testing Setup

This project uses Docker containers for testing to ensure consistent environments and enable proper integration testing.

## Quick Start

```bash
# Run all tests
make test

# Run only unit tests (fast, no external dependencies)
make unit

# Run only integration tests (with HTTP server)
make integration

# Build Docker images
make build

# Clean up everything
make clean
```

## Architecture

### Containers

- **httpbin** - HTTP test server for integration tests (kennethreitz/httpbin)
- **unit-tests** - Runs unit tests only (excludes integration group)
- **integration-tests** - Runs integration tests with HTTP server dependency

### Test Types

- **Unit Tests**: Fast tests that don't require external services
- **Integration Tests**: Tests that make real HTTP requests to the containerized HTTP server

### Environment Variables

- `TEST_HTTP_SERVER`: HTTP server endpoint for integration tests (defaults to `httpbin:80`)

