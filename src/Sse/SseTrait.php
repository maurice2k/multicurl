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

use Maurice\Multicurl\Helper\Stream;
use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Manager;

/**
 * Trait for SSE processing functionality
 *
 * Classes using this trait must implement the abstract methods defined below
 *
 * @author Moritz Fain <moritz@fain.io>
 */
trait SseTrait
{
    /**
     * @var \Closure(SseEvent $event, self $sseChannel, Manager $manager): ?bool
     */
    private $onEventCb = null;

    private ?string $currentEventName = null;
    private string $currentEventData = '';
    private ?string $currentEventId = null;
    private int $retry = 3000; // Default SSE retry in ms

    /**
     * Sets or removes a header
     *
     * @param string $name Name
     * @param string|null $value Value (if null, header is removed)
     */
    abstract public function setHeader(string $name, ?string $value): void;

    /**
     * Sets whether the channel is streamable
     */
    abstract public function setStreamable(bool $streamable): void;

    /**
     * Gets the stream object for buffer manipulation
     *
     * @return Stream Stream object
     */
    abstract public function getStream(): Stream;

    /**
     * Sets onStream callback
     *
     * If the callback returns false, the stream will be closed and the connection aborted.
     * Return null/true in the callback to continue streaming data.
     *
     * @param \Closure(Channel $channel, Stream $stream, Manager $manager): ?bool $onStreamCb
     */
    abstract public function setOnStreamCallback(\Closure $onStreamCb): void;

    /**
     * Sets the callback function that is called when an SSE event is received
     *
     * If the callback returns false, the stream will be closed.
     *
     * @param \Closure(SseEvent $event, self $sseChannel, Manager $manager): ?bool $onEventCb
     */
    public function setOnEventCallback(?\Closure $onEventCb): void
    {
        $this->onEventCb = $onEventCb;
    }

    /**
     * Sets up the required SSE headers and stream settings
     */
    protected function initializeSse(): void
    {
        // SSE requires specific headers
        $this->setHeader('Accept', 'text/event-stream');
        $this->setHeader('Cache-Control', 'no-cache');
        $this->setStreamable(true); // Ensure channel is streamable

        // Set the stream callback to process the stream
        $this->setOnStreamCallback(function(Channel $channel, Stream $stream, Manager $manager): ?bool {
            $res = $this->processSseStream($manager);
            if ($res === false) {
                return false;
            }
            return null;
        });
    }

    /**
     * Processes the internal stream buffer for SSE events.
     *
     * @param Manager $manager The Manager instance for callbacks
     * @return bool True if the stream is still active, false if the stream has ended
     */
    protected function processSseStream(Manager $manager): bool
    {
        while (true) {
            $line = $this->getStream()->consumeLine();

            if ($line === false) {
                // No more complete lines in buffer
                break;
            }

            if ($line === '') {
                // Empty line: dispatch event if data is present
                if ($this->currentEventData !== '') {
                    // Remove last newline from data if present
                    if (str_ends_with($this->currentEventData, "\n")) {
                        $this->currentEventData = substr($this->currentEventData, 0, -1);
                    }

                    $event = new SseEvent($this->currentEventData, $this->currentEventName, $this->currentEventId);
                    // Reset for next event
                    $this->currentEventName = null;
                    $this->currentEventData = '';
                    // $this->currentEventId is not reset as per SSE spec (it persists)

                    if ($this->onEventCb !== null) {
                        $res = ($this->onEventCb)($event, $this, $manager);
                        if ($res === false) {
                            return false; // Abort stream if callback returns false
                        }
                        // If $res is not false (e.g. null or true), continue processing loop.
                    }

                }
                continue;
            }

            if (str_starts_with($line, ':')) {
                // Comment, ignore
                continue;
            }

            $parts = explode(':', $line, 2);
            $field = $parts[0];
            $value = $parts[1] ?? '';

            if (isset($parts[1]) && $parts[1][0] === ' ') {
                $value = substr($value, 1); // Remove leading space if present
            }

            switch ($field) {
                case 'event':
                    $this->currentEventName = $value;
                    break;
                case 'data':
                    $this->currentEventData .= $value . "\n";
                    break;
                case 'id':
                    $this->currentEventId = $value;
                    break;
                case 'retry':
                    if (is_numeric($value)) {
                        $this->retry = (int)$value;
                    }
                    break;
                default:
                    // Ignore unknown fields
                    break;
            }
        }
        return true;
    }

    /**
     * Returns the current SSE retry timeout in milliseconds.
     */
    public function getRetryTimeout(): int
    {
        return $this->retry;
    }

    /**
     * Returns the last event ID received from the server.
     */
    public function getLastEventId(): ?string
    {
        return $this->currentEventId;
    }
}
