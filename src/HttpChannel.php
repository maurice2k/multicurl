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
 * HTTP specific channel
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class HttpChannel extends Channel
{
    /**
     * HTTP version consts
     */
    public const HTTP_1_1 = CURL_HTTP_VERSION_1_1;
    public const HTTP_2_0 = CURL_HTTP_VERSION_2_0;

    /**
     * HTTP method consts
     */
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';

    /**
     * Valid HTTP methods
     *
     * @var array
     */
    protected $validMethods = [self::METHOD_GET, self::METHOD_POST];

    /**
     * HTTP method
     *
     * @var string
     */
    protected $method;

    /**
     * GET/POST body data
     *
     * @var string
     */
    protected $body;

    /**
     * Content-Type for body data
     *
     * @var string
     */
    protected $contentType;

    /**
     * Headers
     *
     * @var array
     */
    protected $headers = [];

    /**
     * Prototype object for ::create factory
     *
     * @var self
     */
    protected static $prototype;

    /**
     * Constructor
     *
     * @param string $url URL
     * @param string $method HTTP Method (see self::METHOD_* consts)
     * @param string|array|null $body Body (string or array)
     * @param string $contentType Content-Type
     */
    public function __construct(string $url, string $method = self::METHOD_GET, $body = null, string $contentType = null)
    {
        $this->setURL($url);
        $this->setMethod($method);
        $this->setBody($body, $contentType);
    }

    /**
     * Cleanup on clone
     *
     * @return void
     */
    public function __clone()
    {
        $this->setURL('');
        $this->setMethod(self::METHOD_GET);
        $this->body = null;
        $this->contentType = null;
        $this->setHeader('content-type', null);
        unset($this->curlOptions[CURLOPT_POST]);
        unset($this->curlOptions[CURLOPT_POSTFIELDS]);
        unset($this->curlOptions[CURLOPT_CUSTOMREQUEST]);
    }

    /**
     * Sets HTTP method
     *
     * @param string $method HTTP Method (see self::METHOD_* consts)
     * @return void
     */
    public function setMethod(string $method = self::METHOD_GET): void
    {
        if (!in_array($method, $this->validMethods)) {
            throw new \InvalidArgumentException('Method "' . $method . '" not allowed; only ' . implode(', ', $this->validMethods));
        }

        $this->method = $method;
    }

    /**
     * Sets body content and type
     *
     * @param mixed $body
     * @param string $contentType
     * @return void
     */
    public function setBody($body, string $contentType = null): void
    {
        $contentType = strtolower((string)$contentType);
        if (is_array($body) && ($contentType === 'text/json' || $contentType === 'application/json')) {
            $body = json_encode($body);
        }
        $this->body = $body;
        if ($contentType !== '') {
            $this->contentType = $contentType;
            $this->setHeader('content-type', $contentType);
        }
    }

    /**
     * Sets HTTP version
     *
     * @param integer $version
     * @return void
     */
    public function setHttpVersion(int $version = null): void
    {
        $this->setCurlOption(CURLOPT_HTTP_VERSION, $version === null ? CURL_HTTP_VERSION_NONE : $version);
    }

    /**
     * Sets or removes a header
     *
     * @param string $name Name
     * @param string $value Value (if null, header is removed)
     * @return void
     */
    public function setHeader(string $name, string $value = null): void
    {
        if ($value === null) {
            // remove header
            unset($this->headers[strtolower($name)]);
        } else {
            $this->headers[strtolower($name)] = strtolower($name) . ': ' . $value;
        }

        $this->setCurlOption(CURLOPT_HTTPHEADER, array_values($this->headers));
    }

    /**
     * Sets user agent
     *
     * @param string $userAgent
     * @return void
     */
    public function setUserAgent(string $userAgent): void
    {
        $this->setCurlOption(CURLOPT_USERAGENT, $userAgent);
    }

    /**
     * Whether or not to follow redirects
     *
     * @param bool $follow
     * @param int $maxRedirects Use -1 for an infinite number of redirects
     * @return void
     */
    public function setFollowRedirects(bool $follow = true, int $maxRedirects = 10): void
    {
        $this->setCurlOption(CURLOPT_FOLLOWLOCATION, $follow);
        $this->setCurlOption(CURLOPT_MAXREDIRS, $maxRedirects);
    }

    /**
     * Sets cookie jar file for reading and writing
     *
     * @param string $cookieJar Cookie jar file path
     * @return void
     */
    public function setCookieJarFile($cookieJar): void
    {
        $this->setCurlOption(CURLOPT_COOKIEJAR, $cookieJar);
        $this->setCurlOption(CURLOPT_COOKIEFILE, $cookieJar);
    }

    /**
     * Returns all set curl options
     *
     * @return array
     */
    public function getCurlOptions() : array
    {
        if ($this->method === self::METHOD_POST) {
            $this->setCurlOption(CURLOPT_POST, true);
            $this->setCurlOption(CURLOPT_POSTFIELDS, $this->body);

        } else if ($this->method === self::METHOD_GET && $this->body != '') {
            $this->setCurlOption(CURLOPT_CUSTOMREQUEST, 'GET');
            $this->setCurlOption(CURLOPT_POSTFIELDS, $this->body);
        }

        return parent::getCurlOptions();
    }

    /**
     * Returns static prototype object
     *
     * @return self
     */
    public static function prototype() : self
    {
        if (static::$prototype === null) {
            static::$prototype = new self('');
        }

        return static::$prototype;
    }

    /**
     * Static factory
     *
     * @param string $url URL
     * @param string $method HTTP Method (see self::METHOD_* consts)
     * @param string $body Body (string or array)
     * @param string $contentType Content-Type
     */
    public static function create(string $url, string $method = self::METHOD_GET, string $body = null, string $contentType = null): self
    {
        $httpChan = clone(self::prototype());
        $httpChan->setURL($url);
        $httpChan->setMethod($method);
        $httpChan->setBody($body, $contentType);
        return $httpChan;
    }
}
