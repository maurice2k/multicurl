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

namespace Maurice\Multicurl\Helper;

/**
 * Stream buffer management
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class Stream
{
    /**
     * Internal buffer
     */
    private string $buffer = '';

    /**
     * Appends data to the buffer
     */
    public function append(string $data): void
    {
        $this->buffer .= $data;
    }

    /**
     * Returns a reference to the buffer for direct manipulation
     */
    public function &getBufferRef(): string
    {
        return $this->buffer;
    }

    /**
     * Returns the current buffer size
     */
    public function getSize(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Returns the current buffer size (alias for getSize).
     */
    public function getLength(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Checks if buffer is empty
     */
    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }

    /**
     * Consumes and returns a line from the buffer (up to \n)
     * 
     * @return string|false Line without \n, or false if no complete line available
     */
    public function consumeLine(): string|false
    {
        $pos = strpos($this->buffer, "\n");
        if ($pos === false) {
            return false;
        }

        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);
        
        // Remove \r if present (handles \r\n line endings)
        return rtrim($line, "\r");
    }

    /**
     * Consumes and returns specified number of bytes from the buffer
     * 
     * @param int $bytes Number of bytes to consume
     * @return string Consumed data (may be less than requested if buffer is smaller)
     */
    public function consumeBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        $consumed = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);
        
        return $consumed;
    }

    /**
     * Consumes and returns data up to a delimiter
     * 
     * @param string $delimiter The delimiter to search for
     * @param bool $includeDelimiter Whether to include the delimiter in the result
     * @return string|false Consumed data, or false if delimiter not found
     */
    public function consumeUntil(string $delimiter, bool $includeDelimiter = false): string|false
    {
        $pos = strpos($this->buffer, $delimiter);
        if ($pos === false) {
            return false;
        }

        $endPos = $includeDelimiter ? $pos + strlen($delimiter) : $pos;
        $consumed = substr($this->buffer, 0, $endPos);
        $this->buffer = substr($this->buffer, $pos + strlen($delimiter));
        
        return $consumed;
    }

    /**
     * Clears the entire buffer
     */
    public function clear(): void
    {
        $this->buffer = '';
    }

    /**
     * Returns the buffer content without consuming it
     */
    public function peek(): string
    {
        return $this->buffer;
    }

    /**
     * Returns the buffer content and clears it
     */
    public function consume(): string
    {
        $data = $this->buffer;
        $this->buffer = '';
        return $data;
    }

    /**
     * Returns the buffer content as string (for implicit string conversion)
     */
    public function __toString(): string
    {
        return $this->buffer;
    }
} 