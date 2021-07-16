<?php
declare(strict_types = 1);

/**
 * Multicurl -- Object based asynchronous multi-curl wrapper
 *
 * Copyright (c) 2018-2021 Moritz Fain
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

/**
 * Base channel
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class Channel
{
    use ContextInfo;

     /**
     * Proxy type consts
     */
    const PROXY_SOCKS5 = CURLPROXY_SOCKS5_HOSTNAME;
    const PROXY_HTTP = CURLPROXY_HTTP;

    /**
     * Timeout type consts
     */
    const TIMEOUT_CONNECTION = 1;
    const TIMEOUT_TOTAL = 2;

    /**
     * URL
     *
     * @var string
     */
    protected $url = '';

    /**
     * Curl options
     *
     * @var array
     */
    protected $curlOptions = [];

    /**
     * onReady callback
     *
     * @var callable
     */
    protected $onReadyCb;

    /**
     * onTimeout callback
     *
     * @var callable
     */
    protected $onTimeoutCb;

    /**
     * onError callback
     *
     * @var callable
     */
    protected $onErrorCb;

    /**
     * Connection timeout
     *
     * @var int
     */
    protected $connectionTimeout;

    /**
     * Sets URL
     *
     * @param string $url
     * @return void
     */
    public function setURL(string $url)
    {
        $this->url = $url;
        $this->setCurlOption(CURLOPT_URL, $this->url);
    }

    /**
     * Returns URL
     *
     * @return string
     */
    public function getURL() : string
    {
        return $this->url;
    }

    /**
     * Sets total timeout in milliseconds
     *
     * @param int $timeout Total timeout in milliseconds (1000ms == 1s) or null if no timeout is required (default)
     * @return void
     */
    public function setTimeout(int $timeout = null)
    {
        $this->setCurlOption(CURLOPT_TIMEOUT_MS, $timeout);
    }

    /**
     * Sets connection timeout in milliseconds
     *
     * INFO: This timeout includes the socket connection as well as the SSL handshake
     *
     * @param int $timeout Connection timeout in milliseconds (1000ms == 1s)
     * @return void
     */
    public function setConnectionTimeout(int $timeout = null)
    {
        $this->setCurlOption(CURLOPT_CONNECTTIMEOUT_MS, $timeout);
        $this->connectionTimeout = (int)$timeout;
    }

    /**
     * Returns the connection timeout in milliseconds
     *
     * The default cURL timeout is 300,000 milliseconds
     * @see https://curl.haxx.se/libcurl/c/CURLOPT_CONNECTTIMEOUT_MS.html
     *
     * @return integer
     */
    public function getConnectionTimeout() : int
    {
        return $this->connectionTimeout === 0 ? 300000 : $this->connectionTimeout;
    }

    /**
     * Enables/disables verbosity
     *
     * @param boolean $verbose true/false
     * @param resource $outputFileHandle Either a file handle or NULL to print to STDERR
     * @return void
     */
    public function setVerbose(bool $verbose = true, $outputFileHandle = null)
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
     * @return void
     */
    public function setCertificateOptions(bool $verifyHostname = true, bool $verifyAgainstCA = true)
    {
        $this->setCurlOption(CURLOPT_SSL_VERIFYHOST, $verifyHostname ? 2 : 0);
        $this->setCurlOption(CURLOPT_SSL_VERIFYPEER, $verifyAgainstCA);
    }

    /**
     * Sets username and password
     *
     * @param string $username
     * @param string $password
     * @return void
     */
    public function setAuthentication(string $username, string $password)
    {
        $this->setCurlOption(CURLOPT_USERPWD, $username . ':' . $password);
    }

    /**
     * Sets options for proxy use
     *
     * @param int $type Type of proxy (see self::PROXY_* consts)
     * @param string $host Proxy hostname
     * @param int $port Proxy port
     * @param string $username Username (or null if not applicable)
     * @param string $password Password (or null if not applicable)
     */
    public function setProxy(int $type, string $host, int $port, string $username = null, string $password = null)
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
     *
     * @param int $option
     * @param mixed $value
     * @return void
     */
    public function setCurlOption($option, $value)
    {
        $this->curlOptions[$option] = $value;
    }

    /**
     * Returns all set curl options
     *
     * @return array
     */
    public function getCurlOptions() : array
    {
        return $this->curlOptions;
    }

    /**
     * Sets onReady callback
     *
     * @param callable $onReadyCb function(Channel $channel, array $info, $content, Manager $manager)
     * @return void
     */
    public function setOnReadyCallback(callable $onReadyCb)
    {
        $this->onReadyCb = $onReadyCb;
    }

    /**
     * Sets onTimeout callback
     *
     * @param callable $onTimeoutCb function(Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager)
     * @return void
     */
    public function setOnTimeoutCallback(callable $onTimeoutCb)
    {
        $this->onTimeoutCb = $onTimeoutCb;
    }

    /**
     * Sets onError callback
     *
     * @param callable $onErrorCb function(Channel $channel, string $message, $errno, array $info, Manager $manager)
     * @return void
     */
    public function setOnErrorCallback(callable $onErrorCb)
    {
        $this->onErrorCb = $onErrorCb;
    }

    /**
     * Called from Manager when curl channel is ready and no error occured
     *
     * @param array $info Output of curl_getinfo (@see https://php.net/curl_getinfo)
     * @param mixed $content
     * @param Manager $manager Manager instance
     * @return void
     */
    public function onReady(array $info, $content, Manager $manager)
    {
        call_user_func($this->onReadyCb, $this, $info, $content, $manager);
    }

    /**
     * Called from Manager when curl channel is timed out
     *
     * @param integer $timeoutType Type of timeout, either TIMEOUT_CONNECTION or TIMEOUT_TOTAL
     * @param integer $elapsedMS Elapsed milliseconds (1000ms = 1s)
     * @param Manager $manager Manager instance
     * @return void
     */
    public function onTimeout(int $timeoutType, int $elapsedMS, Manager $manager)
    {
        call_user_func($this->onTimeoutCb, $this, $timeoutType, $elapsedMS, $manager);
    }

    /**
     * Called from Manager when curl encountered an error other than timeout
     *
     * @param string $message Curl error message
     * @param integer $errno Curl error code (@see https://curl.haxx.se/libcurl/c/libcurl-errors.html)
     * @param array $info Output of curl_getinfo (@see https://php.net/curl_getinfo)
     * @param Manager $manager Manager instance
     * @return void
     */
    public function onError(string $message, int $errno, array $info, Manager $manager)
    {
        call_user_func($this->onErrorCb, $this, $message, $errno, $info, $manager);
    }
}
