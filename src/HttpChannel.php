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
     * @var array<int, string>
     */
    protected array $validMethods = [self::METHOD_GET, self::METHOD_POST];

    /**
     * HTTP method
     */
    protected string $method;

    /**
     * GET/POST body data
     */
    protected ?string $body = null;

    /**
     * Content-Type for body data
     *
     * @var string|null
     */
    protected ?string $contentType = null;

    /**
     * Headers
     *
     * @var array<array-key, string>
     */
    protected array $headers = [];

    /**
     * Prototype object for ::create factory
     *
     * @var HttpChannel
     */
    protected static ?HttpChannel $prototype = null;

    /**
     * Whether to show the curl command
     */
    protected bool $showCurlCommand = false;

    /**
     * Constructor
     *
     * @param string $url URL
     * @param string $method HTTP Method (see self::METHOD_* consts)
     * @param string|array<array-key, mixed>|null $body Body (string or array)
     * @param string|null $contentType Content-Type
     */
    public function __construct(
        string $url,
        string $method = self::METHOD_GET,
        string|array|null $body = null,
        ?string $contentType = null
    ) {
        $this->setURL($url);
        $this->setMethod($method);
        $this->setBody($body, $contentType);
    }

    /**
     * Sets HTTP method
     *
     * @param string $method HTTP Method (see self::METHOD_* consts)
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
     * @param string|array<array-key, mixed>|null $body
     */
    public function setBody(string|array|null $body, ?string $contentType = null): void
    {
        $contentType = strtolower((string)$contentType);

        if (is_array($body)) {

            if (empty($contentType)) {
                // default to JSON if no content type is set and we have an array
                $contentType = 'application/json';
            }

            if ($contentType === 'text/json' || $contentType === 'application/json') {
                // JSON encoding for arrays
                $encoded = json_encode($body);
                if ($encoded === false) {
                    throw new \InvalidArgumentException('Failed to JSON encode array body');
                }
                $this->body = $encoded;
            } elseif ($contentType === 'application/x-www-form-urlencoded') {
                // Form URL encoding for arrays
                $this->body = http_build_query($body);
            } else {
                throw new \InvalidArgumentException('Array body requires content type to be application/json, text/json, or application/x-www-form-urlencoded');
            }
        } else {
            $this->body = $body;
        }

        if ($contentType !== '') {
            $this->contentType = $contentType;
            $this->setHeader('content-type', $contentType);
        }
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Sets HTTP version
     */
    public function setHttpVersion(?int $version = null): void
    {
        $this->setCurlOption(CURLOPT_HTTP_VERSION, $version === null ? CURL_HTTP_VERSION_NONE : $version);
    }

    /**
     * Sets or removes a header
     *
     * @param string $name Name
     * @param string|null $value Value (if null, header is removed)
     */
    public function setHeader(string $name, ?string $value = null): void
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
     */
    public function setUserAgent(string $userAgent): void
    {
        $this->setCurlOption(CURLOPT_USERAGENT, $userAgent);
    }

    public function setBasicAuth(string $username, string $password): void
    {
        $this->setCurlOption(CURLOPT_USERPWD, $username . ':' . $password);
    }

    public function setBearerAuth(string $token): void
    {
        $this->setHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * Whether or not to follow redirects
     *
     * @param int $maxRedirects Use -1 for an infinite number of redirects
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
     */
    public function setCookieJarFile(string $cookieJar): void
    {
        $this->setCurlOption(CURLOPT_COOKIEJAR, $cookieJar);
        $this->setCurlOption(CURLOPT_COOKIEFILE, $cookieJar);
    }

    /**
     * Returns all set curl options
     *
     * @return array<array-key, mixed>
     */
    public function getCurlOptions(): array
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
     * Generates a curl command string for debugging purposes.
     */
    public function generateCurlCommand(): string
    {
        $command = 'curl ';

        // URL
        $command .= escapeshellarg($this->getURL()) . " ";

        // Method
        if ($this->method === self::METHOD_POST) {
            $command .= "-X POST ";
        } elseif ($this->method !== self::METHOD_GET) {
            $command .= "-X " . escapeshellarg(strtoupper($this->method)) . " ";
        }

        // Headers
        foreach ($this->headers as $header) {
            $command .= "-H " . escapeshellarg($header) . " ";
        }

        // Body
        if ($this->body !== null && $this->body !== '') {
            $command .= "-d " . escapeshellarg($this->body) . " ";
        }

        // Other curl options
        $curlOptions = parent::getCurlOptions(); // Use parent to avoid re-adding POSTFIELDS/CUSTOMREQUEST
        foreach ($curlOptions as $option => $value) {
            switch ($option) {
                case CURLOPT_HTTP_VERSION:
                    if ($value === self::HTTP_1_1) {
                        $command .= "--http1.1 ";
                    } elseif ($value === self::HTTP_2_0) {
                        $command .= "--http2 ";
                    }
                    break;
                case CURLOPT_USERAGENT:
                    if (is_string($value)) {
                        $command .= "-A " . escapeshellarg($value) . " ";
                    }
                    break;
                case CURLOPT_USERPWD:
                    if (is_string($value)) {
                        $command .= "-u " . escapeshellarg($value) . " ";
                    }
                    break;
                case CURLOPT_FOLLOWLOCATION:
                    if ($value) {
                        $command .= "-L ";
                    }
                    break;
                case CURLOPT_MAXREDIRS:
                    if (($curlOptions[CURLOPT_FOLLOWLOCATION] ?? false) && is_int($value)) {
                        $command .= "--max-redirs " . escapeshellarg((string)$value) . " ";
                    }
                    break;
                case CURLOPT_COOKIEJAR:
                    if (is_string($value)) {
                        $command .= "-c " . escapeshellarg($value) . " ";
                    }
                    break;
                case CURLOPT_COOKIEFILE:
                    // only add if not already added by COOKIEJAR
                    if (is_string($value) && (!isset($curlOptions[CURLOPT_COOKIEJAR]) || $curlOptions[CURLOPT_COOKIEJAR] !== $value)) {
                         $command .= "-b " . escapeshellarg($value) . " ";
                    }
                    break;
                case CURLOPT_TIMEOUT:
                    if (is_int($value)) {
                        $command .= "--max-time " . escapeshellarg((string)$value) . " ";
                    }
                    break;
                case CURLOPT_TIMEOUT_MS:
                    if (is_int($value)) {
                        $command .= "--max-time " . escapeshellarg((string)($value / 1000)) . " ";
                    }
                    break;
                case CURLOPT_CONNECTTIMEOUT:
                    if (is_int($value)) {
                        $command .= "--connect-timeout " . escapeshellarg((string)$value) . " ";
                    }
                    break;
                case CURLOPT_CONNECTTIMEOUT_MS:
                    if (is_int($value)) {
                        $command .= "--connect-timeout " . escapeshellarg((string)($value / 1000)) . " ";
                    }
                    break;
                // Skip options already handled or not directly translatable to CLI
                case CURLOPT_URL:
                case CURLOPT_POST:
                case CURLOPT_POSTFIELDS:
                case CURLOPT_CUSTOMREQUEST:
                case CURLOPT_HTTPHEADER:
                case CURLOPT_HEADERFUNCTION:
                case CURLOPT_RETURNTRANSFER:
                case CURLOPT_WRITEFUNCTION:
                case CURLOPT_VERBOSE: // Handled by the caller
                case CURLOPT_FAILONERROR: // default behavior for curl CLI
                    break;
                default:
                    // For unhandled options, you might want to log them or add a generic way to represent them if possible
                    // For now, we'll just skip them to avoid errors.
                    break;
            }
        }

        return trim($command);
    }

    /**
     * Sets whether to show the curl command
     */
    public function setShowCurlCommand(bool $showCurlCommand): void
    {
        $this->showCurlCommand = $showCurlCommand;
    }

    /**
     * Returns whether to show the curl command
     */
    public function isShowCurlCommand(): bool
    {
        return $this->showCurlCommand;
    }

    /**
     * Cleanup on clone
     */
    public function __clone(): void
    {
        parent::__clone();

        unset($this->curlOptions[CURLOPT_POST]);
        unset($this->curlOptions[CURLOPT_POSTFIELDS]);
        unset($this->curlOptions[CURLOPT_CUSTOMREQUEST]);
    }

    /**
     * Returns static prototype object
     */
    public static function prototype(): self
    {
        if (static::$prototype === null) {
            static::$prototype = new self('');
        }

        return static::$prototype;
    }

    /**
     * Static factory
     *
     * @param string|array<array-key, mixed>|null $body
     */
    public static function create(
        string $url,
        string $method = self::METHOD_GET,
        string|array|null $body = null,
        ?string $contentType = null
    ): self {
        $httpChan = clone(self::prototype());
        $httpChan->setURL($url);
        $httpChan->setMethod($method);
        $httpChan->setBody($body, $contentType);
        return $httpChan;
    }
}
