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

namespace Maurice\Multicurl\Mcp;

/**
 * JSON-RPC message for Model Context Protocol
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class RpcMessage
{
    /**
     * JSON-RPC version (always 2.0)
     */
    protected string $jsonrpc = '2.0';
    
    /**
     * Message type: request, notification, or response
     */
    protected string $type;
    
    /**
     * Method name for requests and notifications
     */
    protected ?string $method = null;
    
    /**
     * Parameters for requests and notifications
     */
    protected mixed $params = null;
    
    /**
     * Result for successful responses
     */
    protected mixed $result = null;
    
    /**
     * Error for error responses
     * 
     * @var array<string, mixed>|null
     */
    protected ?array $error = null;
    
    /**
     * Message ID for requests and responses (null for notifications)
     */
    protected mixed $id = null;
    
    /**
     * Constants for message types
     */
    public const TYPE_REQUEST = 'request';
    public const TYPE_NOTIFICATION = 'notification';
    public const TYPE_RESPONSE = 'response';
    public const TYPE_ERROR = 'error';
    
    /**
     * Generate a unique message ID
     */
    protected static function getNextId(): string 
    {
        static $counter = 0;
        return (string)++$counter;
    }
    
    /**
     * Create a new initialize request message
     *
     * @param array<string, mixed>|null $clientInfo
     * @param array<string, mixed>|null $capabilities
     */
    public static function initializeRequest(
        string $protocolVersion = '2025-03-26',
        ?array $clientInfo = null,
        ?array $capabilities = null,
        mixed $id = null
    ): self {
        $params = [
            'protocolVersion' => $protocolVersion,
            'capabilities' => $capabilities ?? new \stdClass(),
            'clientInfo' => $clientInfo ?? [
                'name' => 'maurice2k/multicurl MCP Client',
                'version' => '1.0.0', // Consider making this dynamic or configurable
            ],
        ];

        return self::request('initialize', $params, $id);
    }

    /**
     * Create a new tools/list request message
     */
    public static function toolsListRequest(mixed $id = null): self
    {
        return self::request('tools/list', null, $id);
    }

    /**
     * Create a new request message
     */
    public static function request(string $method, mixed $params = null, mixed $id = null): self
    {
        if ($id === null) {
            $id = self::getNextId();
        }
        
        $message = new self();
        $message->type = self::TYPE_REQUEST;
        $message->method = $method;
        $message->params = empty($params) ? new \stdClass() : $params;
        $message->id = $id;
        
        return $message;
    }
    
    /**
     * Create a new notification message (request without ID)
     */
    public static function notification(string $method, mixed $params = null): self
    {
        $message = new self();
        $message->type = self::TYPE_NOTIFICATION;
        $message->method = $method;
        $message->params = $params;
        
        return $message;
    }
    
    /**
     * Create a new response message
     */
    public static function response(mixed $result, mixed $id): self
    {
        $message = new self();
        $message->type = self::TYPE_RESPONSE;
        $message->result = $result;
        $message->id = $id;
        
        return $message;
    }
    
    /**
     * Create a new error response message
     */
    public static function error(int $code, string $message, mixed $data = null, mixed $id = null): self
    {
        $rpcMessage = new self();
        $rpcMessage->type = self::TYPE_ERROR;
        $rpcMessage->error = [
            'code' => $code,
            'message' => $message
        ];
        
        if ($data !== null) {
            $rpcMessage->error['data'] = $data;
        }
        
        $rpcMessage->id = $id;
        
        return $rpcMessage;
    }
    
    /**
     * Parse JSON string into RpcMessage
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Parse array into RpcMessage
     * 
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $message = new self();
        
        // Verify JSON-RPC version
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new \InvalidArgumentException('Invalid or no JSON-RPC version in message: ' . json_encode($data));
        }
        
        // Determine message type
        if (isset($data['method'])) {
            if (isset($data['id'])) {
                $message->type = self::TYPE_REQUEST;
                $message->id = $data['id'];
            } else {
                $message->type = self::TYPE_NOTIFICATION;
            }
            $message->method = $data['method'];
            $message->params = $data['params'] ?? null;
        } else {
            if (isset($data['error'])) {
                $message->type = self::TYPE_ERROR;
                $message->error = $data['error'];
            } else {
                $message->type = self::TYPE_RESPONSE;
                $message->result = $data['result'] ?? null;
            }
            $message->id = $data['id'] ?? null;
        }
        
        return $message;
    }
    
    /**
     * Convert to array for JSON encoding
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'jsonrpc' => $this->jsonrpc
        ];
        
        if ($this->type === self::TYPE_REQUEST || $this->type === self::TYPE_NOTIFICATION) {
            $result['method'] = $this->method;
            
            if ($this->params !== null) {
                $result['params'] = $this->params;
            }
            
            if ($this->type === self::TYPE_REQUEST) {
                $result['id'] = $this->id;
            }
        } else {
            if ($this->type === self::TYPE_ERROR) {
                $result['error'] = $this->error;
            } else {
                $result['result'] = $this->result;
            }
            
            if ($this->id !== null) {
                $result['id'] = $this->id;
            }
        }
        
        return $result;
    }
    
    /**
     * Convert to JSON string
     */
    public function toJson(): string
    {
        $json = json_encode($this->toArray());
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }
        return $json;
    }
    
    /**
     * Get message type
     */
    public function getType(): string
    {
        return $this->type;
    }
    
    /**
     * Get method name
     */
    public function getMethod(): ?string
    {
        return $this->method;
    }
    
    /**
     * Get parameters
     */
    public function getParams(): mixed
    {
        return $this->params;
    }
    
    /**
     * Get result
     */
    public function getResult(): mixed
    {
        return $this->result;
    }
    
    /**
     * Get error
     * 
     * @return array<string, mixed>|null
     */
    public function getError(): ?array
    {
        return $this->error;
    }
    
    /**
     * Get ID
     */
    public function getId(): mixed
    {
        return $this->id;
    }
    
    /**
     * Check if message is a request
     */
    public function isRequest(): bool
    {
        return $this->type === self::TYPE_REQUEST;
    }
    
    /**
     * Check if message is a notification
     */
    public function isNotification(): bool
    {
        return $this->type === self::TYPE_NOTIFICATION;
    }
    
    /**
     * Check if message is a response
     */
    public function isResponse(): bool
    {
        return $this->type === self::TYPE_RESPONSE;
    }
    
    /**
     * Check if message is an error
     */
    public function isError(): bool
    {
        return $this->type === self::TYPE_ERROR;
    }
} 