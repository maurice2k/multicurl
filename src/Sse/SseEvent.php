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

namespace Maurice\Multicurl\Sse;

/**
 * Represents a Server-Sent Event.
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class SseEvent
{
    /**
     * The event name. Null for unnamed (message) events.
     */
    public readonly ?string $name;

    /**
     * The event data.
     */
    public readonly string $data;

    /**
     * The event ID, if provided by the server.
     */
    public readonly ?string $id;

    /**
     * Constructor for SseEvent.
     *
     * @param string $data The event data (mandatory).
     * @param ?string $name The event name (optional, defaults to null for 'message' events).
     * @param ?string $id The event ID (optional).
     */
    public function __construct(string $data, ?string $name = null, ?string $id = null)
    {
        $this->data = $data;
        $this->name = $name;
        $this->id = $id;
    }
} 