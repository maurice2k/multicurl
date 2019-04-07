<?php
declare(strict_types = 1);

/**
 * Multicurl -- Object based asynchronous multi-curl wrapper
 *
 * Copyright (c) 2018 Moritz Fain
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
    const HTTP_1_1 = CURL_HTTP_VERSION_1_1;
    const HTTP_2_0 = CURL_HTTP_VERSION_2_0;

    /**
     * HTTP method consts
     */
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';

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
    protected $data;

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
    protected $headers;

    /**
     * Constructor
     *
     * @param string $url
     * @param string $method
     * @param string $data
     * @param string $contentType
     */
    public function __construct(string $url, string $method = self::METHOD_GET, string $data = null, string $contentType = null)
    {
        if (!in_array($method, $this->validMethods)) {
            throw new \InvalidArgumentException('Method "' . $method . '" not allowed; only ' . implode(', ', $this->validMethods));
        }

        $this->setURL($url);
        $this->setUserAgent('Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.110 Safari/537.36');
        $this->method = $method;
        $this->data = $data;

        if ($contentType != '') {
            $this->contentType = $contentType;
            $this->setHeader('content-type', $contentType);
        }

        if ($method === self::METHOD_POST) {
            $this->setCurlOption(CURLOPT_POST, true);
            $this->setCurlOption(CURLOPT_POSTFIELDS, $data);

        } else if ($method === self::METHOD_GET && $data != '') {
            $this->setCurlOption(CURLOPT_CUSTOMREQUEST, 'GET');
            $this->setCurlOption(CURLOPT_POSTFIELDS, $data);
        }
    }

    /**
     * Sets HTTP version
     *
     * @param integer $version
     * @return void
     */
    public function setHttpVersion(int $version = null)
    {
        $this->setCurlOption(CURLOPT_HTTP_VERSION, $version === null ? CURL_HTTP_VERSION_NONE : $version);
    }

    /**
     * Sets a header
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    public function setHeader(string $name, string $value)
    {
        $this->headers[strtolower($name)] = strtolower($name) . ': ' . $value;
        $this->setCurlOption(CURLOPT_HTTPHEADER, array_values($this->headers));
    }

    /**
     * Sets user agent
     *
     * @param string $userAgent
     * @return void
     */
    public function setUserAgent(string $userAgent)
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
    public function setFollowRedirects(bool $follow = true, int $maxRedirects = 10)
    {
        $this->setCurlOption(CURLOPT_FOLLOWLOCATION, $follow);
        $this->setCurlOption(CURLOPT_MAXREDIRS, $maxRedirects);
    }
}
