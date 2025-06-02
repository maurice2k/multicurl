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
     * onMcpMessage callback
     *
     * @var \Closure(RpcMessage, self): ?bool
     */
    private ?\Closure $onMcpMessageCb = null;

    /**
     * onException callback
     *
     * @var \Closure(\Exception, self): void
     */
    private ?\Closure $onExceptionCb = null;

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
    }

    private function setupResponseHeaderCallback(): void
    {
        // Set up header callback to detect response type early
        $this->setCurlOption(CURLOPT_HEADERFUNCTION, function(\CurlHandle $ch, string $headerLine) {
            $len = strlen($headerLine);
            $header = trim($headerLine);

            // Skip empty lines
            if (empty($header)) {
                return $len;
            }

            // Process content type
            if (stripos($header, 'Content-Type:') === 0) {
                $responseContentType = trim(substr($header, 13));

                // Set up SSE processing only if we detect an event stream
                if (stripos($responseContentType, 'text/event-stream') === false) {
                    $this->setStreamable(false);
                }
            }

            // Extract MCP session ID if present
            if (stripos($header, 'Mcp-Session-Id:') === 0) {
                $sessionId = trim(substr($header, 15));
                $this->setSessionId($sessionId);
            }

            return $len;
        });

        $this->setCurlOption(CURLOPT_FAILONERROR, true);
    }

    private function setupMessageCallbacks(): void
    {
        // Hook into SSE events to process MCP messages
        $this->setOnEventCallback(function($event, $channel): ?bool {
            // SSE events with JSON-RPC data
            if ($event->data) {
                $res = $this->processJsonMessage($event->data);
                return $res;
            }
            return null;
        });
       
        // Hook into regular response handling
        parent::setOnReadyCallback(function($channel, $info, $stream, $manager) {
            // Only process JSON responses here (SSE is handled via onEventCallback)
            if (!$this->isStreamable() && $stream->getSize() > 0) {
                $this->processJsonMessage($stream->peek());
            }
        });
    }

    public function setOnReadyCallback(\Closure $onReadyCb): void
    {
        throw new \Exception('setOnReadyCallback is not supported for McpChannel, use setOnMcpMessageCallback instead');
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
     * Get the current MCP session ID
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
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
     * @param \Closure(RpcMessage, self): ?bool $onMcpMessageCb
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
    private function onMcpMessage(RpcMessage $message): bool
    {
        if ($this->onMcpMessageCb !== null) {
            $res = ($this->onMcpMessageCb)($message, $this);
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
     * Process JSON data for MCP messages - handles both single messages and batches
     */
    protected function processJsonMessage(string $json): ?bool
    {
        $data = json_decode($json, true);
        if ($data === null) 
        {
            // Invalid JSON, ignore
            return null;
        }
        if (is_array($data) && isset($data[0])) {
            // Batch of messages
            foreach ($data as $item) {
                try {
                    $message = RpcMessage::fromArray($item);
                    return $this->onMcpMessage($message);
                } catch (\Exception $e) {
                    // Skip invalid messages
                    $this->onException($e);
                }
            }
        } else {
            // Single message
            try {
                $message = RpcMessage::fromArray($data);
                return $this->onMcpMessage($message);
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

    public function __clone(): void
    {
        parent::__clone();

        $this->currentEventData = '';
        $this->currentEventName = '';
        $this->currentEventId = '';

        $this->setupSse();
        $this->setupResponseHeaderCallback();
        $this->setupMessageCallbacks();
    }
} 