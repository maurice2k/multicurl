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

use Maurice\Multicurl\Helper\ContextInfo;
use Maurice\Multicurl\Helper\Stream;

/**
 * Base channel
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class Channel
{
    use ContextInfo;

    /**
     * CurlHandle instance associated with this channel
     */
    protected ?\CurlHandle $curlHandle = null;

    /**
     * Proxy type consts
     */
    public const PROXY_SOCKS5 = CURLPROXY_SOCKS5_HOSTNAME;
    public const PROXY_HTTP = CURLPROXY_HTTP;

    /**
     * Timeout type consts
     */
    public const TIMEOUT_CONNECTION = 1;
    public const TIMEOUT_TOTAL = 2;

    /**
     * URL
     */
    protected string $url = '';

    /**
     * Curl options
     *
     * @var array<array-key, mixed>
     */
    protected array $curlOptions = [];

    /**
     * onReady callback
     *
     * @var \Closure(Channel, array<array-key, mixed>, Stream, Manager): void|null
     */
    private $onReadyCb;

    /**
     * onTimeout callback
     *
     * Called when a timeout occurs during the request. There are two types of timeouts:
     * - TIMEOUT_CONNECTION: Connection establishment timeout (socket + SSL handshake)
     * - TIMEOUT_TOTAL: Total operation timeout (connection + data transfer)
     *
     * @var \Closure(Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager): void|null
     *      $channel - The channel that timed out
     *      $timeoutType - Type of timeout (Channel::TIMEOUT_CONNECTION or Channel::TIMEOUT_TOTAL)
     *      $elapsedMS - Elapsed time in milliseconds when timeout occurred
     *      $manager - The manager instance handling the request
     */
    private $onTimeoutCb;

    /**
     * onError callback
     *
     * Called when a cURL error occurs during the request (excluding timeouts, which have their own callback).
     * Common errors include DNS resolution failures, connection refused, SSL certificate errors, etc.
     *
     * @var \Closure(Channel $channel, string $message, int $errno, array<array-key, mixed> $info, Manager $manager): void|null
     *      $channel - The channel that encountered the error
     *      $message - Human-readable error message from cURL
     *      $errno - cURL error code (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)
     *      $info - Output of curl_getinfo() containing request details
     *      $manager - The manager instance handling the request
     */
    private $onErrorCb;

    /**
     * onStream callback
     *
     * If the callback returns false, the stream will be closed and the connection aborted.
     * Return null/true in the callback to continue streaming data.
     *
     * @var \Closure(Channel $channel, Stream $stream, Manager $manager): ?bool
     *      $channel - The channel that is streaming data
     *      $stream - The stream object for buffer manipulation
     *      $manager - The manager instance handling the request
     */
    private $onStreamCb;

    /**
     * Connection timeout
     */
    protected int $connectionTimeout;

    /**
     * Whether the channel is streamable
     */
    protected bool $streamable = false;

    /**
     * Stream object for buffer management
     */
    protected ?Stream $stream = null;

    /**
     * Whether the stream was aborted
     */
    protected bool $streamAborted = false;

    /**
     * Next channel to be added to the manager when this channel is done
     */
    protected ?Channel $nextChannel = null;

    /**
     * Channel to be executed before this channel is executed
     */
    protected ?Channel $beforeChannel = null;

    /**
     * Sets URL
     */
    public function setURL(string $url): void
    {
        $this->url = $url;
        $this->setCurlOption(CURLOPT_URL, $this->url);
    }

    /**
     * Returns URL
     */
    public function getURL(): string
    {
        return $this->url;
    }

    /**
     * Sets total timeout in milliseconds
     *
     * @param int|null $timeout Total timeout in milliseconds (1000ms == 1s) or null if no timeout is required (default)
     */
    public function setTimeout(?int $timeout = null): void
    {
        $this->setCurlOption(CURLOPT_TIMEOUT_MS, $timeout);
    }

    /**
     * Sets connection timeout in milliseconds
     *
     * INFO: This timeout includes the socket connection as well as the SSL handshake
     *
     * @param int|null $timeout Connection timeout in milliseconds (1000ms == 1s)
     */
    public function setConnectionTimeout(?int $timeout = null): void
    {
        $this->setCurlOption(CURLOPT_CONNECTTIMEOUT_MS, $timeout);
        $this->connectionTimeout = (int)$timeout;
    }

    /**
     * Returns the connection timeout in milliseconds
     *
     * The default cURL timeout is 300,000 milliseconds (300 seconds)
     * @see https://curl.haxx.se/libcurl/c/CURLOPT_CONNECTTIMEOUT_MS.html
     */
    public function getConnectionTimeout(): int
    {
        return $this->connectionTimeout === 0 ? 300_000 : $this->connectionTimeout;
    }

    /**
     * Sets whether the channel is streamable
     */
    public function setStreamable(bool $streamable = true): void
    {
        $this->streamable = $streamable;
    }

    /**
     * Returns whether the channel is streamable
     */
    public function isStreamable(): bool
    {
        return $this->streamable;
    }

    /**
     * Returns whether the stream was aborted by the client (e.g., onStream callback returned false).
     */
    public function isStreamAborted(): bool
    {
        return $this->streamAborted;
    }

    /**
     * Enables/disables verbosity
     *
     * @param resource $outputFileHandle Either a file handle or NULL to print to STDERR
     */
    public function setVerbose(bool $verbose = true, $outputFileHandle = null): void
    {
        $this->setCurlOption(CURLOPT_VERBOSE, $verbose);

        if (is_resource($outputFileHandle) && get_resource_type($outputFileHandle) === 'stream') {
            $this->setCurlOption(CURLOPT_STDERR, $outputFileHandle);
        }
    }

    /**
     * Sets SSL certificate verification options
     *
     * @param bool $verifyHostname Whether or not to verify/match the hostname from the certificate against the server hostname
     * @param bool $verifyAgainstCA Whether or not the certificate chain is checked against curls CA store
     */
    public function setCertificateOptions(bool $verifyHostname = true, bool $verifyAgainstCA = true): void
    {
        $this->setCurlOption(CURLOPT_SSL_VERIFYHOST, $verifyHostname ? 2 : 0);
        $this->setCurlOption(CURLOPT_SSL_VERIFYPEER, $verifyAgainstCA);
    }

    /**
     * Sets username and password
     */
    public function setAuthentication(string $username, string $password): void
    {
        $this->setCurlOption(CURLOPT_USERPWD, $username . ':' . $password);
    }

    /**
     * Sets options for proxy use
     *
     * @param int $type Type of proxy (see self::PROXY_* consts)
     * @param string|null $username Username (or null if not applicable)
     * @param string|null $password Password (or null if not applicable)
     */
    public function setProxy(int $type, string $host, int $port, ?string $username = null, ?string $password = null): void
    {
        if ($type === self::PROXY_SOCKS5) {
            $this->setCurlOption(CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } else {
            $this->setCurlOption(CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        $this->setCurlOption(CURLOPT_PROXY, $host . ':' . $port);

        if ($username !== null) {
            $this->setCurlOption(CURLOPT_PROXYAUTH, CURLAUTH_ANY);
            $this->setCurlOption(CURLOPT_PROXYUSERPWD, $username . ':' . $password);
        }
    }

    /**
     * Sets a specific curl option
     *
     * @see https://php.net/curl_setopt
     */
    public function setCurlOption(int $option, mixed $value): void
    {
        $this->curlOptions[$option] = $value;
    }

    /**
     * Returns all set curl options
     *
     * @return array<array-key, mixed>
     */
    public function getCurlOptions(): array
    {
        return $this->curlOptions;
    }

    /**
     * Sets the CurlHandle for this channel.
     */
    public function setCurlHandle(?\CurlHandle $curlHandle): void
    {
        $this->curlHandle = $curlHandle;
    }

    /**
     * Called when the channel is complete
     *
     * Can be used to cleanup any resources associated with the channel
     *
     * @param Manager $manager The manager that removed the channel
     */
    public function onComplete(Manager $manager): void
    {
    }

    /**
     * Returns the CurlHandle associated with this channel.
     */
    public function getCurlHandle(): ?\CurlHandle
    {
        return $this->curlHandle;
    }

    /**
     * Sets onReady callback
     *
     * @param \Closure(Channel $channel, array<array-key, mixed> $info, Stream $stream, Manager $manager): void $onReadyCb
     *      $channel - The channel that is ready
     *      $info - Output of curl_getinfo() containing request details
     *      $stream - The stream object for buffer manipulation
     *      $manager - The manager instance handling the request
     */
    public function setOnReadyCallback(\Closure $onReadyCb): void
    {
        $this->onReadyCb = $onReadyCb;
    }

    /**
     * Sets onTimeout callback
     *
     * @param \Closure(Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager): void $onTimeoutCb
     *      $channel - The channel that timed out
     *      $timeoutType - Type of timeout (Channel::TIMEOUT_CONNECTION or Channel::TIMEOUT_TOTAL)
     *      $elapsedMS - Elapsed time in milliseconds when timeout occurred
     *      $manager - The manager instance handling the request
     */
    public function setOnTimeoutCallback(\Closure $onTimeoutCb): void
    {
        $this->onTimeoutCb = $onTimeoutCb;
    }

    /**
     * Sets onError callback
     *
     * @param \Closure(Channel $channel, string $message, int $errno, array<array-key, mixed> $info, Manager $manager): void $onErrorCb
     *      $channel - The channel that encountered the error
     *      $message - Human-readable error message from cURL
     *      $errno - cURL error code (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)
     *      $info - Output of curl_getinfo() containing request details
     *      $manager - The manager instance handling the request
     */
    public function setOnErrorCallback(\Closure $onErrorCb): void
    {
        $this->onErrorCb = $onErrorCb;
    }

    /**
     * Sets onStream callback
     *
     * If the callback returns false, the stream will be closed and the connection aborted.
     * Return null/true in the callback to continue streaming data.
     *
     * @param \Closure(Channel $channel, Stream $stream, Manager $manager): ?bool $onStreamCb
     *      $channel - The channel that is streaming data
     *      $stream - The stream object for buffer manipulation
     *      $manager - The manager instance handling the request
     */
    public function setOnStreamCallback(\Closure $onStreamCb): void
    {
        $this->onStreamCb = $onStreamCb;
        $this->setStreamable(true);
    }

    /**
     * Appends a channel to the end of the nextChannel chain.
     * If there's no nextChannel, it simply sets it.
     * If there's already a chain, finds the last channel and appends there.
     */
    public function appendNextChannel(Channel $channel): void
    {
        if ($this->nextChannel === null) {
            $this->nextChannel = $channel;
            return;
        }

        // Find the last channel in the chain
        $current = $this->nextChannel;
        while ($current->nextChannel !== null) {
            $current = $current->nextChannel;
        }

        // Append the new channel
        $current->nextChannel = $channel;
    }

    /**
     * Sets a channel to be executed before this one is executed.
     *
     * @param Channel $channel The channel to execute before this one
     * @param bool $setThisAsNext Whether to automatically set this channel as the next channel of the before channel
     */
    public function setBeforeChannel(Channel $channel, bool $setThisAsNext = false): void
    {
        $this->beforeChannel = $channel;

        if ($setThisAsNext) {
            $channel->appendNextChannel($this);
        }
    }

    /**
     * Gets and removes the next channel to be executed.
     *
     * @return Channel|null The next channel, or null if none is set.
     */
    public function popNextChannel(): ?Channel
    {
        $next = $this->nextChannel;
        $this->nextChannel = null;
        return $next;
    }

    /**
     * Gets and removes the before channel to be executed.
     *
     * @return Channel|null The before channel, or null if none is set.
     */
    public function popBeforeChannel(): ?Channel
    {
        $before = $this->beforeChannel;
        $this->beforeChannel = null;
        return $before;
    }

    /**
     * Gets the stream object for buffer manipulation, creating one if it doesn't exist
     *
     * @return Stream Stream object (always returns a valid Stream)
     */
    public function getStream(): Stream
    {
        if ($this->stream === null) {
            $this->stream = new Stream();
        }
        return $this->stream;
    }

    /**
     * Called from Manager when curl channel is ready and no error occured
     *
     * @param array<array-key, mixed> $info Output of curl_getinfo (@see https://php.net/curl_getinfo)
     * @param string $data The response data
     */
    public function onReady(array $info, string $data, Manager $manager): void
    {
        // Always append data to the stream buffer
        $this->getStream()->append($data);

        if ($this->onReadyCb !== null) {
            call_user_func($this->onReadyCb, $this, $info, $this->getStream(), $manager);
        }
    }

    /**
     * Called from Manager when curl channel is timed out
     *
     * @param int $timeoutType Type of timeout, either TIMEOUT_CONNECTION or TIMEOUT_TOTAL
     * @param int $elapsedMS Elapsed milliseconds (1000ms = 1s)
     */
    public function onTimeout(int $timeoutType, int $elapsedMS, Manager $manager): void
    {
        if ($this->onTimeoutCb !== null) {
            call_user_func($this->onTimeoutCb, $this, $timeoutType, $elapsedMS, $manager);
        }
    }

    /**
     * Called from Manager when curl encountered an error other than timeout
     *
     * @param string $message Curl error message
     * @param int $errno Curl error code (@see https://curl.haxx.se/libcurl/c/libcurl-errors.html)
     * @param array<array-key, mixed> $info Output of curl_getinfo (@see https://php.net/curl_getinfo)
     */
    public function onError(string $message, int $errno, array $info, Manager $manager): void
    {
        if ($this->streamAborted && $errno === CURLE_WRITE_ERROR) {
            // Ignore write errors if the stream was aborted
            return;
        }

        if ($this->onErrorCb !== null) {
            call_user_func($this->onErrorCb, $this, $message, $errno, $info, $manager);
        } else {
            throw new \RuntimeException('No error callback set for channel ' . $this->getURL() . ' when error occurred: ' . $message);
        }
    }

    /**
     * Called from Manager when data is received for streaming channels
     *
     * If this method returns any value other than strlen($data), the connection will be aborted.
     * In this case a cURL error 23 (CURLE_WRITE_ERROR) will be returned to the Manager, which we will ignore.
     *
     * @param string $data The received data chunk
     * @return int Number of bytes written (must return strlen($data) to continue)
     */
    public final function onStream(string $data, Manager $manager): int
    {
        // Always append data to the stream buffer
        $this->getStream()->append($data);

        if ($this->isStreamable()) {
            // we check here for streamable because it could have been disabled after the stream was
            // created and we cannot disable cURL's CURLOPT_WRITEFUNCTION after the request has been sent

            if ($this->onStreamCb === null) {
                return strlen($data);
            }

            $res = call_user_func($this->onStreamCb, $this, $this->getStream(), $manager);
            if ($res === false) {
                $this->streamAborted = true;
                return 0; // abort connection
            }
        }
        return strlen($data);
    }

    public function __clone(): void
    {
        $this->stream = null;
        $this->streamAborted = false;
        $this->setCurlHandle(null);
        $this->nextChannel = null;
        $this->beforeChannel = null;
    }
}
