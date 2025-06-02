#!/bin/bash

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." &> /dev/null && pwd )"

# Check if a script name was provided
if [ -z "$1" ]; then
    echo "Usage: $0 <example_script.php>"
    echo "Available examples:"
    find "$SCRIPT_DIR" -maxdepth 1 -name "*.php" -exec basename {} \; | sort
    exit 1
fi

# Get the script name and ensure it's a full path
if [[ "$1" == /* ]]; then
    # Absolute path provided
    SCRIPT_PATH="$1"
else
    # Relative path, make it absolute
    SCRIPT_PATH="$( cd "$( dirname "$1" )" &> /dev/null && pwd )/$(basename "$1")"
    # If just a filename was provided, assume it's in the examples directory
    if [ ! -f "$SCRIPT_PATH" ]; then
        SCRIPT_PATH="$SCRIPT_DIR/$(basename "$1")"
    fi
fi

# Check if the script exists
if [ ! -f "$SCRIPT_PATH" ]; then
    echo "Error: Script '$1' not found"
    echo "Available examples:"
    find "$SCRIPT_DIR" -maxdepth 1 -name "*.php" -exec basename {} \; | sort
    exit 1
fi

# Run the script in the PHP 8.4 container
echo "Running $(basename "$SCRIPT_PATH") in PHP 8.4 container..."
cd "$PROJECT_ROOT"
docker-compose run --rm -v "$PROJECT_ROOT":/app php84 php /app/examples/$(basename "$SCRIPT_PATH") 