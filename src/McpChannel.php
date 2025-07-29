<?php
declare(strict_types = 1);

/**
 * Multicurl -- Object based asynchronous multi-curl wrapper
 *
 * Copyright (c) 2018-2025 Moritz Fain
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Maurice\Multicurl;

use Maurice\Multicurl\Helper\Stream;
use Maurice\Multicurl\Sse\SseEvent;
use Maurice\Multicurl\Sse\SseTrait;
use Maurice\Multicurl\Mcp\RpcMessage;

/**
 * MCP (Model Context Protocol) Streamable HTTP client channel
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class McpChannel extends HttpChannel
{
    use SseTrait;

    /**
     * MCP session ID received from server
     */
    protected ?string $sessionId = null;

    /**
     * Last event ID for stream resumption
     */
    protected ?string $lastEventId = null;

    /**
     * Initialize channel for automatic initialization
     */
    protected ?McpChannel $initializeChannel = null;

    /**
     * onMcpMessage callback
     *
     * @var \Closure(RpcMessage, self, Manager): ?bool
     */
    private ?\Closure $onMcpMessageCb = null;

    /**
     * onException callback
     *
     * @var \Closure(\Exception, self): void
     */
    private ?\Closure $onExceptionCb = null;

    /**
     * Response content type from server
     */
    protected string $responseContentType = '';

    /**
     * HTTP response status code
     */
    protected int $httpStatusCode = 0;

    /**
     * Internal error handling callback for automatic initialization
     */
    protected ?\Closure $internalErrorHandler = null;

    /**
     * Constructor with setup for both SSE and regular JSON response handling
     *
     * @param string $url MCP endpoint URL
     * @param RpcMessage|null $rpcMessage JSON-RPC message to send
     */
    public function __construct(
        string $url,
        private ?RpcMessage $rpcMessage = null,
    ) {
        $method = $rpcMessage ? self::METHOD_POST : self::METHOD_GET;
        parent::__construct($url, $method, $rpcMessage ? $rpcMessage->toJson() : null, 'application/json');

        $this->setRpcMessage($rpcMessage);

        $this->setupSse();
        $this->setupResponseHeaderCallback();
        $this->setupMessageCallbacks();

        $this->setFollowRedirects(true, 2);
    }

    private function setupResponseHeaderCallback(): void
    {
        // Set up header callback to detect response type early
        $this->setCurlOption(CURLOPT_HEADERFUNCTION, function(\CurlHandle $ch, string $headerLine) {

            $len = strlen($headerLine);
            $header = trim($headerLine);

            // Extract HTTP status code from the first line
            if (preg_match('/^HTTP\/[\d\.]+\s+(\d+)/', $header, $matches)) {
                $this->httpStatusCode = (int)$matches[1];

                // This is the beginning of the response, so we (re-)set the streamable flag to true,
                // which is the default after calling setupSse() (which evenutally calls setStreamable(true)).
                // The issue is that we set it to false once we detect a non-SSE response, but this might
                // be a 30x redirect, so we need to set it to true again for the upcoming headers.
                $this->responseContentType = '';
                $this->setStreamable(true);
            }

            if (empty($header)) { // end of headers
                // Set up SSE processing only if we detected an event stream
                if (stripos($this->responseContentType, 'text/event-stream') === false || $this->httpStatusCode >= 400) {
                    $this->setStreamable(false);
                }
            }

            // Process content type
            if (stripos($header, 'Content-Type:') === 0) {
                $responseContentType = trim(substr($header, 13));
                $this->responseContentType = $responseContentType;
            }

            // Extract MCP session ID if present
            if (stripos($header, 'Mcp-Session-Id:') === 0) {
                $sessionId = trim(substr($header, 15));
                $this->setSessionId($sessionId);
            }

            return $len;
        });

        //$this->setCurlOption(CURLOPT_FAILONERROR, true);
    }

    private function setupMessageCallbacks(): void
    {
        // Hook into SSE events to process MCP messages
        $this->setOnEventCallback(function(SseEvent $event, McpChannel $channel, Manager $manager): ?bool {
            // SSE events with JSON-RPC data
            if ($event->data) {
                $res = $this->processJsonMessage($event->data, $manager);
                return $res;
            }
            return null;
        });

        // Hook into regular response handling
        parent::setOnReadyCallback(function(Channel $channel, array $info, Stream $stream, Manager $manager) {
            if ($channel->getHttpStatusCode() >= 400) {
                $this->onError('HTTP request failed: ' . $channel->getHttpStatusCode(), CURLE_HTTP_RETURNED_ERROR, $info, $manager);
                return false;
            }

            if (!$this->isStreamable() && $stream->getSize() > 0) {
                // Only process normal JSON responses here (SSE is handled via onEventCallback)
                $this->processJsonMessage($stream->peek(), $manager);
            }
        });
    }

    public function setOnReadyCallback(\Closure $onReadyCb): void
    {
        throw new \Exception('setOnReadyCallback is not supported for McpChannel, use setOnMcpMessageCallback or setOnErrorCallback instead');
    }

    /**
     * Set MCP session ID for subsequent requests
     */
    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;

        if ($sessionId !== null) {
            $this->setHeader('Mcp-Session-Id', $sessionId);
        } else {
            $this->setHeader('Mcp-Session-Id', null);
        }
    }

    /**
     * Override setHeader to also update the initialize channel if it exists
     */
    public function setHeader(string $name, ?string $value = null): void
    {
        parent::setHeader($name, $value);

        // Also update the initialize channel if it exists
        if ($this->initializeChannel !== null && $this !== $this->initializeChannel) {
            $this->initializeChannel->setHeader($name, $value);
        }
    }

    /**
     * Override setCurlOption to also update the initialize channel if it exists
     */
    public function setCurlOption(int $option, mixed $value): void
    {
        parent::setCurlOption($option, $value);

        // Also update the initialize channel if it exists, except for CURLOPT_HTTPHEADER
        // which would cause double propagation (once through setCurlOption and once through setHeader)
        if ($this->initializeChannel !== null && $this !== $this->initializeChannel && $option !== CURLOPT_HTTPHEADER) {
            $this->initializeChannel->setCurlOption($option, $value);
        }
    }

    /**
     * Get the current MCP session ID
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Get the HTTP response status code
     */
    protected function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Set Last-Event-ID header for stream resumption
     */
    public function setLastEventIdHeader(?string $eventId): void
    {
        $this->lastEventId = $eventId;

        if ($eventId !== null) {
            $this->setHeader('Last-Event-ID', $eventId);
        } else {
            $this->setHeader('Last-Event-ID', null);
        }
    }

    /**
     * Set OAuth 2.1 Bearer token with Resource Indicators support
     *
     * @param string $token The access token
     * @param string|null $resourceIndicator Optional resource indicator (RFC 8707)
     */
    public function setOAuthToken(string $token, ?string $resourceIndicator = null): void
    {
        $this->setBearerAuth($token);

        if ($resourceIndicator !== null) {
            $this->setHeader('Resource-Indicator', $resourceIndicator);
        }
    }

    /**
     * Set Resource Indicator header for OAuth 2.1 compliance
     *
     * @param string $resourceIndicator The resource indicator URI
     */
    public function setResourceIndicator(string $resourceIndicator): void
    {
        $this->setHeader('Resource-Indicator', $resourceIndicator);
    }

    /**
     * Enable stream resumption using the last received event ID
     */
    public function enableStreamResumption(): void
    {
        $this->setLastEventIdHeader($this->getLastEventId());
    }

    /**
     * Sets the callback function that is called when an MCP message is received
     *
     * If the callback returns false, the stream will be closed (if it was a streamable channel).
     *
     * @param \Closure(RpcMessage, self, Manager): ?bool $onMcpMessageCb
     */
    public function setOnMcpMessageCallback(\Closure $onMcpMessageCb): void
    {
        $this->onMcpMessageCb = $onMcpMessageCb;
    }

    /**
     * Sets the callback function that is called when an exception occurs during message processing
     *
     * @param \Closure(\Exception, self): void $onExceptionCb
     */
    public function setOnExceptionCallback(\Closure $onExceptionCb): void
    {
        $this->onExceptionCb = $onExceptionCb;
    }

    /**
     * Process an MCP message and trigger the callback
     */
    private function onMcpMessage(RpcMessage $message, Manager $manager): bool
    {
        if ($this->onMcpMessageCb !== null) {
            $res = ($this->onMcpMessageCb)($message, $this, $manager);
            if ($res === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Trigger the exception callback
     */
    private function onException(\Exception $exception): void
    {
        if ($this->onExceptionCb !== null) {
            ($this->onExceptionCb)($exception, $this);
        }
    }

    /**
     * Check if an exception callback has been set
     */
    public function hasExceptionCallback(): bool
    {
        return $this->onExceptionCb !== null;
    }

    /**
     * Process JSON data for MCP messages - handles both single messages and batches
     *
     * @param string $json JSON data to process
     * @param Manager $manager Manager instance for callback
     * @return bool|null Return value from the callback
     */
    protected function processJsonMessage(string $json, Manager $manager): ?bool
    {
        $data = json_decode($json, true);
        if ($data === null) {
            // Invalid JSON, ignore
            return null;
        }

        if (is_array($data) && isset($data[0])) {
            // Batch of messages
            $res = true;
            foreach ($data as $item) {
                try {
                    $message = RpcMessage::fromArray($item);
                    $res = $res && $this->onMcpMessage($message, $manager);
                    if ($message->isError()) {
                        // if the message is an error the overall result will be false
                        $res = false;
                    }
                } catch (\Exception $e) {
                    // Skip invalid messages
                    $this->onException($e);
                }
            }
            return $res;
        } else {
            // Single message
            try {
                $message = RpcMessage::fromArray($data);
                $res = $this->onMcpMessage($message, $manager);
                if ($message->isError()) {
                    // if the message is an error, stop processing
                    $res = false;
                }
                return $res;
            } catch (\Exception $e) {
                // Ignore invalid message
                $this->onException($e);
            }
        }
        return null;
    }

    protected function setupSse(): void
    {
        $this->initializeSse();

        // Set Accept header again, to support both JSON and SSE as per MCP spec
        $this->setHeader('Accept', 'application/json, text/event-stream');
    }

    /**
     * Updates the request with a new RPC message
     */
    public function setRpcMessage(?RpcMessage $rpcMessage): void
    {
        $this->rpcMessage = $rpcMessage;
        $this->setBody($rpcMessage ? $rpcMessage->toJson() : null);
    }

    public function getRpcMessage(): RpcMessage
    {
        if ($this->rpcMessage === null) {
            throw new \Exception('RpcMessage is not set');
        }
        return $this->rpcMessage;
    }

    /**
     * Sets up automatic initialization for MCP communication
     *
     * This creates a chain of channels for proper MCP initialization:
     * 1. Initialize request (RPC message "initialize")
     * 2. Initialized notification (RPC message "notifications/initialized")
     *
     * The session ID from initialization will be set to this channel.
     *
     * @param array<string, mixed>|null $clientInfo Optional client info to use in initialization
     * @param array<string, mixed>|null $capabilities Optional capabilities to use in initialization
     * @param \Closure(?string): void|null $onInitializedCallback Optional callback called upon successful initialization with session ID
     */
    public function setAutomaticInitialize(
        ?array $clientInfo = null,
        ?array $capabilities = null,
        ?\Closure $onInitializedCallback = null
    ): void {
        // Create the initialization channel that will be executed when needed
        $this->initializeChannel = clone $this;
        $this->initializeChannel->setRpcMessage(RpcMessage::initializeRequest(
            '2025-06-18',
            $clientInfo,
            $capabilities
        ));

        // Set up the initialization callback
        $mainChannel = $this; // Reference to the main channel to set session ID

        $this->initializeChannel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use ($mainChannel, $onInitializedCallback) {
            if ($message->isError()) {
                // Propagate error to caller via exception
                throw new \RuntimeException('MCP initialization error: ' .
                    ($message->getError()['message'] ?? 'Unknown error') .
                    ' (Code: ' . ($message->getError()['code'] ?? 'unknown') . ')');
            }

            if ($message->isResponse() && $message->getId() == $channel->getRpcMessage()->getId()) {
                if ($message->getResult()) {
                    $result = $message->getResult();

                    // update the main channel's session ID
                    $mainChannel->setSessionId($channel->getSessionId());

                    // Call the initialization callback with session ID if provided
                    if ($onInitializedCallback !== null) {
                        $onInitializedCallback($channel->getSessionId());
                    }

                    $initializedNotificationChannel = clone($channel);
                    $initializedNotificationChannel->setRpcMessage(RpcMessage::notification('notifications/initialized'));
                    $initializedNotificationChannel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) {
                        // some MCP servers will hang if the connection is not closed after the initialized notification is sent
                        return false; // force closing the connection
                    });
                    $initializedNotificationChannel->appendNextChannel($mainChannel);  // this calls the main channel's logic

                    $channel->appendNextChannel($initializedNotificationChannel);

                    return false;
                }
            }

            return true;
        });

        // Forward exceptions from initialization channel to main channel
        $this->initializeChannel->setOnExceptionCallback(function (\Exception $exception, McpChannel $channel) use ($mainChannel) {
            // Forward the exception to the main channel using our helper method
            $mainChannel->forwardException($exception);
        });

        // Forward errors from initialization channel to main channel
        $this->initializeChannel->setOnErrorCallback(function (Channel $channel, string $error, int $code, array $info, Manager $manager) use ($mainChannel) {
            // Forward the error to the main channel
            $mainChannel->onError($error, $code, $info, $manager);
        });

        // Forward timeouts from initialization channel to main channel
        $this->initializeChannel->setOnTimeoutCallback(function (Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager) use ($mainChannel) {
            $mainChannel->onTimeout($timeoutType, $elapsedMS, $manager);
        });

        // If we already have a session ID, don't initialize immediately
        // Instead, set up internal 404 handling to trigger initialization
        if ($this->sessionId !== null) {
                        // Set up internal error handler that will be called from onError
            $this->internalErrorHandler = function (Channel $channel, string $error, int $code, array $info, Manager $manager) use ($mainChannel) {
                // Check if this is a 404 error (HTTP status code was already extracted in header callback)

                if ($mainChannel->httpStatusCode === 404 ||
                    preg_match('/session.*?(not found|expired)|no valid session/i', $channel->getStream()->peek()))
                {
                    // Clear the session ID and trigger initialization
                    $mainChannel->setSessionId(null);
                    $mainChannel->setBeforeChannel($mainChannel->initializeChannel);

                    // Re-add the channel to the manager to trigger the initialization flow
                    $manager->addChannel($mainChannel);
                    return true; // Indicate we handled the error
                }
                return false; // Let normal error handling continue
            };
        } else {
            // No session ID, set up initialization to run before the main channel
            $this->setBeforeChannel($this->initializeChannel);
        }
    }

    public function onError(string $error, int $code, array $info, Manager $manager): void
    {
        // First, check our internal error handler
        if ($this->internalErrorHandler !== null) {
            $handled = ($this->internalErrorHandler)($this, $error, $code, $info, $manager);
            if ($handled) {
                return; // Error was handled, don't continue with normal error processing
            }
        }

        // Continue with normal error processing
        parent::onError($error, $code, $info, $manager);
    }

    public function onComplete(Manager $manager): void
    {
        parent::onComplete($manager);

        // Clear the internal handler
        $this->httpStatusCode = 0;
        $this->internalErrorHandler = null;
    }

    public function __clone(): void
    {
        parent::__clone();

        $this->currentEventData = '';
        $this->currentEventName = '';
        $this->currentEventId = '';

        $this->initializeChannel = null;
        $this->responseContentType = '';
        $this->httpStatusCode = 0;
        $this->internalErrorHandler = null;

        $this->setupSse();
        $this->setupResponseHeaderCallback();
        $this->setupMessageCallbacks();
    }

    /**
     * Forward an exception to this channel's exception handler
     *
     * This is a public method that can be used to trigger the exception handler
     * from another channel, e.g. when forwarding exceptions from the initialize channel.
     *
     * @param \Exception $exception Exception to forward to this channel's handler
     * @param string $context Optional context information for the exception
     */
    public function forwardException(\Exception $exception, string $context = ''): void
    {
        if ($this->onExceptionCb !== null) {
            // Wrap the exception with context information if provided
            if ($context) {
                $exception = new \RuntimeException(
                    "{$context}: " . $exception->getMessage(),
                    (int)$exception->getCode(),
                    $exception
                );
            }

            // Call the exception callback directly
            ($this->onExceptionCb)($exception, $this);
        } else {
            // No handler, just rethrow
            throw $exception;
        }
    }
}
