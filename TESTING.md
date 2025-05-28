# Testing Setup

This project uses Docker containers for testing to ensure consistent environments and enable proper integration testing.

## Quick Start

```bash
# Run all tests with default PHP 8.4
make test

# Run only unit tests (fast, no external dependencies)
make unit

# Run only integration tests (with HTTP server)
make integration

# Test with specific PHP version
make test-8.1   # Test with PHP 8.1
make test-8.2   # Test with PHP 8.2
make test-8.3   # Test with PHP 8.3
make test-8.4   # Test with PHP 8.4

# Run all tests on all supported PHP versions (8.1, 8.2, 8.3, 8.4)
make test-all

# Run specific test type with specific PHP version
make unit-8.3       # Run unit tests with PHP 8.3
make integration-8.2 # Run integration tests with PHP 8.2

# Build Docker images
make build

# Clean up everything
make clean
```

## Architecture

### Containers

- **httpbin** - HTTP test server for integration tests
- **php81** - PHP 8.1 container for running tests
- **php82** - PHP 8.2 container for running tests
- **php83** - PHP 8.3 container for running tests
- **php84** - PHP 8.4 container for running tests (default)

### Testing Approach

The docker-compose.yml file defines a container for each PHP version. The Makefile provides targets to run phpunit inside these containers with the appropriate flags.

For each PHP version, we have:
- A dedicated container (e.g., php81, php82, etc.)
- Make targets to run unit and integration tests

### PHP and PHPUnit Versions

The project can be tested with multiple PHP versions:
- PHP 8.1 
- PHP 8.2 
- PHP 8.3 
- PHP 8.4 (default)

A single Dockerfile is used with build arguments to specify the PHP version for each container. The Dockerfile automatically installs a compatible PHPUnit version based on the PHP version:
- PHPUnit ^10.0 for PHP 8.1 and 8.2
- PHPUnit ^12.0 for PHP 8.3 and 8.4

This ensures that tests can run on all supported PHP versions with the appropriate test runner.

### Test Types

- **Unit Tests**: Fast tests that don't require external services
- **Integration Tests**: Tests that make real HTTP requests to the containerized HTTP server

### Environment Variables

- `TEST_HTTP_SERVER`: HTTP server endpoint for integration tests (defaults to `httpbin:8080`)

