# maurice2k/multicurl

`Maurice\Multicurl` provides a powerful, easy-to-use object-oriented interface for PHP's `curl_multi_*` functions, enabling high-performance concurrent HTTP requests with automatic connection management.

Beyond being just a wrapper, it includes a complete event loop system that intelligently manages concurrent connections, enforces connection limits, and handles both connection and total timeouts automatically.

## Key Components

**`Manager`** - Orchestrates multiple channels, handling the event loop and connection concurrency automatically.

**`HttpChannel`** - The primary class you'll use for making HTTP requests. It provides a clean, fluent API for GET/POST requests, authentication, headers, redirects, and more.

**Specialized Channels:**
- **`McpChannel`** - Implements a "Streamable HTTP" client for the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/specification/2025-03-26) for AI model communication
- **`SseChannel`** - Handles [Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html) for real-time streaming


## Installation

Install with composer:
```bash
$ composer require maurice2k/multicurl
```

## Compatibility

`Maurice\Multicurl` requires PHP 8.1 (or better) with the curl extension enabled.


## Usage


### Basic example

```php
<?php

use Maurice\Multicurl\{Manager, Channel, HttpChannel, Helper\Stream};

$urls = [
    'https://www.google.com/',
    'https://www.facebook.com/',
    'https://www.amazon.com/',
    'https://www.ebay.com/',
    'https://www.example.org/',
    'https://non-existant.this-is-a-dns-error.org/',
    'https://www.netflix.com/',
    'https://www.microsoft.com/',
];

$manager = new Manager(2);  // allow two concurrent connections

// set defaults for all channels that are being instantiated using HttpChannel::create()
HttpChannel::prototype()->setConnectionTimeout(200);
HttpChannel::prototype()->setTimeout(5000);
HttpChannel::prototype()->setFollowRedirects(true);
HttpChannel::prototype()->setCookieJarFile('cookies.txt');

foreach ($urls as $url) {

    $chan = HttpChannel::create($url);

    $chan->setOnReadyCallback(function(Channel $channel, array $info, Stream $stream, Manager $manager) {
        $length = $stream->getLength();
        $streamContent = $stream->consume();
        echo "[X] Successfully loaded '" . $channel->getURL() . "' (" . $length . " bytes, status code " . $info['http_code'] . ")\n";
    });

    $chan->setOnTimeoutCallback(function(Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager) {
        echo "[T] " . ($timeoutType == Channel::TIMEOUT_CONNECTION ? "Connection" : "Global") . " timeout after {$elapsedMS} ms for '" . $channel->getURL() . "'\n";
    });

    $chan->setOnErrorCallback(function(Channel $channel, string $message, $errno, array $info, Manager $manager) {
        echo "[E] cURL error #{$errno}: '{$message}' for '" . $channel->getURL() . "'\n";
    });

    $manager->addChannel($chan);
}

$manager->run();

```

Outputs something like this:
```
[X] Successfully loaded 'https://www.google.com/' (47769 bytes, status code 200)
[X] Successfully loaded 'https://www.facebook.com/' (136682 bytes, status code 200)
[X] Successfully loaded 'https://www.ebay.com/' (287403 bytes, status code 200)
[X] Successfully loaded 'https://www.amazon.com/' (102336 bytes, status code 200)
[E] cURL error #6: 'Couldn't resolve host name' for 'https://non-existant.this-is-a-dns-error.org/'
[T] Connection timeout after 200 ms for 'https://www.example.org/'
[X] Successfully loaded 'https://www.microsoft.com/' (183702 bytes, status code 200)
[X] Successfully loaded 'https://www.netflix.com/' (428858 bytes, status code 200)
```

### Adding new channels on the fly

In this example we're implementing a super simple web crawler that starts at Wikipedia's "Web Crawler" page and extracts at most five new pages per crawled page until 20 pages have been put into the manager (successfully crawled or not).

A cleaner way would have been to create a `HttpCrawlChannel` (extending `HttpChannel`) that directly implements and overwrites HttpChannel's `onReady` method (as well as `onTimeout` and `onError`).

```php
<?php

use Maurice\Multicurl\{Manager, Channel, HttpChannel, Helper\Stream};

$counter = new \stdClass();
$counter->links = 20;

$manager = new Manager(2);  // allow two concurrent connections
$manager->setContext($counter);

$chan = new HttpChannel('https://en.wikipedia.org/wiki/Web_crawler');
$chan->setConnectionTimeout(500);
$chan->setTimeout(5000);
$chan->setFollowRedirects(true);
$chan->setCookieJarFile('cookies.txt');

$chan->setOnReadyCallback(function(Channel $channel, array $info, Stream $stream, Manager $manager) {
    $length = $stream->getLength();
    $streamContent = $stream->consume();
    echo "[X] Successfully loaded '" . $channel->getURL() . "' (" . $length . " bytes)\n";

    if ($manager->getContext()->links > 0) {

        if (!preg_match_all('#<a[^>]+?href="(/wiki/[^:]+?)"[^>]*?>#', $streamContent, $matches)) {
            return;
        }

        $relativeLinks = array_unique($matches[1]);
        shuffle($relativeLinks);
        $relativeLinks = array_slice($relativeLinks, 0, min($manager->getContext()->links, 5));

        foreach ($relativeLinks as $relativeLink) {

            $urlinfo = parse_url($info['url']);
            $newUrl = $urlinfo['scheme'] . '://' . $urlinfo['host'] . $relativeLink;

            $newChan = clone $channel;
            $newChan->setURL($newUrl);
            $manager->addChannel($newChan);

            $manager->getContext()->links--;

        }
    }
});

$chan->setOnTimeoutCallback(function(Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager) {
    echo "[T] " . ($timeoutType == Channel::TIMEOUT_CONNECTION ? "Connection" : "Global") . " timeout after {$elapsedMS} ms for '" . $channel->getURL() . "'\n";
});

$chan->setOnErrorCallback(function(Channel $channel, string $message, $errno, array $info, Manager $manager) {
    echo "[E] cURL error #{$errno}: '{$message}' for '" . $channel->getURL() . "'\n";
});

$manager->addChannel($chan);

$manager->run();

```

Outputs something like this:
```
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Web_crawler' (175116 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Video_search_engine' (57989 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Selection-based_search' (43810 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Unix' (221806 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Online_search' (37052 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Bing_(search_engine)' (274871 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/FAST_Crawler' (175471 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Google_Videos' (34469 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Speech_recognition' (283155 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Phonemes' (163499 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/CastTV' (32544 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Multisearch' (34057 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Federated_search' (58325 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Natural_language_search_engine' (77872 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Search_engine_optimization' (178195 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/User_space' (65509 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Cloud_services' (311577 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Pax_(Unix)' (59822 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Paging' (142101 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/Nice_(Unix)' (57852 bytes)
[X] Successfully loaded 'https://en.wikipedia.org/wiki/AIX_operating_system' (187416 bytes)
```

### Create a MCP client using McpChannel

This example demonstrates how to use `McpChannel` to connect to a remote MCP server and list available tools.
> Works for newer MCP protocol version 2025-03-26 and above ("streamable HTTP").

```php
<?php

use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Manager;
use Maurice\Multicurl\McpChannel;
use Maurice\Multicurl\Mcp\RpcMessage;

$mcpUrl = 'https://remote.mcpservers.org/fetch/mcp';

$manager = new Manager();

$listToolsChannel = new McpChannel($mcpUrl, RpcMessage::toolsListRequest());
//$listToolsChannel->setShowCurlCommand(true);
//$listToolsChannel->setVerbose(true);

// Automatically initialize MCP channel (two more requests will be sent)
$listToolsChannel->setAutomaticInitialize();

// Set up MCP message handling for the tools/list response
$listToolsChannel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel) use ($manager) {
    echo "Received MCP Message (ID: {$message->getId()}, Type: {$message->getType()})\n";

    if ($message->isResponse() && $message->getId() === $channel->getRpcMessage()->getId()) { // Response to tools/list request
        if ($message->getResult() && isset($message->getResult()['tools'])) {
            echo "Available Tools:\n";
            foreach ($message->getResult()['tools'] as $tool) {
                // Build function-like definition with parameters
                $functionDef = $tool['name'];
                if (isset($tool['inputSchema']) && isset($tool['inputSchema']['properties'])) {
                    $params = [];
                    foreach ($tool['inputSchema']['properties'] as $paramName => $paramProps) {
                        $type = $paramProps['type'] ?? 'mixed';
                        $required = in_array($paramName, $tool['inputSchema']['required'] ?? []) ? '' : '?';
                        $params[] = "{$type}{$required} \\${$paramName}";
                    }
                    $functionDef .= "(" . implode(', ', $params) . ")";
                } else {
                    $functionDef .= "()";
                }

                echo "- Name: {$functionDef}\n";
                echo "  Description: ".($tool['description'] ?? 'No description available')."\n";

                // Optionally print inputSchema
                // echo "  Input Schema: " . json_encode($tool['inputSchema']) . "\n";
            }
        } elseif ($message->getError()) {
            echo "Error listing tools: {$message->getError()['message']} (Code: {$message->getError()['code']})\n";
        } else {
            echo "Unexpected response format for tools/list\n";
        }

        return false; // abort connection
    } else if ($message->isError()) {
        echo "Error during tools listing: {$message->getError()['message']} (Code: {$message->getError()['code']})\n";
    }
});

// Set up error handling for the main channel
$listToolsChannel->setOnErrorCallback(function(Channel $channel, string $error, int $code, array $info, Manager $manager) {
    echo "Error: {$error} (Code: {$code})\n";
    echo "HTTP status: " . $info['http_code'] . "\n";
});

// Add the channel to the manager
$manager->addChannel($listToolsChannel);

// Execute the requests
$manager->run();

```

Outputs something like this (will vary based on the server's available tools):

```
Received MCP Message (ID: 1, Type: response)
Available Tools:
- Name: fetch(string $url, number? $max_length, number? $start_index, boolean? $raw)
  Description: No description available
```


## Testing

To run tests with a specific PHP version:

```bash
# Run all tests with PHP 8.1
make test-8.1

# Run all tests with PHP 8.2
make test-8.2

# Run all tests with PHP 8.3
make test-8.3

# Run all tests with PHP 8.4 (default)
make test-8.4

# or simply
make test
```

You can also run specific test types:

```bash
# Run unit tests only with PHP 8.3
make unit-8.3

# Run integration tests only with PHP 8.2
make integration-8.2
```

To run tests with all supported PHP versions (8.1, 8.2, 8.3, 8.4):

```bash
make test-all
```
