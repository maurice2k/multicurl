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
 * MCP Batch Channel for handling batched JSON-RPC messages
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class McpBatchChannel extends McpChannel
{
    /**
     * Array of RPC messages to batch
     *
     * @var array<int, RpcMessage>
     */
    protected array $messages = [];
    
    /**
     * Constructor
     *
     * @param string $url MCP endpoint URL
     * @param array<int, RpcMessage> $messages Initial messages to batch
     */
    public function __construct(
        string $url,
        array $messages = []
    ) {
        parent::__construct($url, self::METHOD_POST, null);
        
        foreach ($messages as $message) {
            $this->addMessage($message);
        }
        
        // Update body with initial messages if provided
        if (!empty($this->messages)) {
            $this->updateRequestBody();
        }
    }
    
    /**
     * Add a message to the batch
     */
    public function addMessage(RpcMessage $message): void
    {
        $this->messages[] = $message;
        $this->updateRequestBody();
    }
    
    /**
     * Add multiple messages to the batch
     * 
     * @param array<int, RpcMessage> $messages
     */
    public function addMessages(array $messages): void
    {
        foreach ($messages as $message) {
            if (!($message instanceof RpcMessage)) {
                throw new \InvalidArgumentException('All elements must be RpcMessage instances');
            }
            $this->messages[] = $message;
        }
        
        $this->updateRequestBody();
    }
    
    /**
     * Clear all messages from the batch
     */
    public function clearMessages(): void
    {
        $this->messages = [];
        $this->updateRequestBody();
    }
    
    /**
     * Get all messages in the batch
     * 
     * @return array<int, RpcMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
    
    /**
     * Update the request body with the current messages
     */
    protected function updateRequestBody(): void
    {
        if (empty($this->messages)) {
            $this->setBody(null);
            return;
        }
        
        $jsonArray = array_map(fn(RpcMessage $message) => $message->toArray(), $this->messages);
        $json = json_encode($jsonArray);
        
        if ($json === false) {
            throw new \RuntimeException('Failed to encode batch JSON: ' . json_last_error_msg());
        }
        
        $this->setBody($json, 'application/json');
    }
} 