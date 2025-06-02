<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\HttpChannel;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HttpChannel
 */
class HttpChannelTest extends TestCase
{
    public function testSetBodyWithJsonArray(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = ['name' => 'test', 'value' => 123];

        $channel->setBody($body, 'application/json');

        $curlOptions = $channel->getCurlOptions();
        $this->assertEquals('{"name":"test","value":123}', $curlOptions[CURLOPT_POSTFIELDS]);
    }

    public function testSetBodyWithTextJsonArray(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = ['foo' => 'bar', 'baz' => [1, 2, 3]];

        $channel->setBody($body, 'text/json');

        $curlOptions = $channel->getCurlOptions();
        $this->assertEquals('{"foo":"bar","baz":[1,2,3]}', $curlOptions[CURLOPT_POSTFIELDS]);
    }

    public function testSetBodyWithFormUrlencodedArray(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = ['username' => 'john', 'password' => 'secret123'];

        $channel->setBody($body, 'application/x-www-form-urlencoded');

        $curlOptions = $channel->getCurlOptions();
        $this->assertEquals('username=john&password=secret123', $curlOptions[CURLOPT_POSTFIELDS]);
    }

    public function testSetBodyWithStringBody(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = 'raw string data';

        $channel->setBody($body, 'text/plain');

        $curlOptions = $channel->getCurlOptions();
        $this->assertEquals('raw string data', $curlOptions[CURLOPT_POSTFIELDS]);
    }

    public function testSetBodyWithNullBody(): void
    {
        $channel = new HttpChannel('http://example.com');

        $channel->setBody(null, 'application/json');

        $curlOptions = $channel->getCurlOptions();
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $curlOptions);
    }

    public function testSetBodyWithArrayAndUnsupportedContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $channel = new HttpChannel('http://example.com');
        $body = ['key' => 'value'];

        $channel->setBody($body, 'text/plain');
    }

    public function testSetBodyWithArrayAndNoContentType(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = ['key' => 'value'];

        $channel->setBody($body);

        $curlOptions = $channel->getCurlOptions();
        $this->assertEquals('{"key":"value"}', $curlOptions[CURLOPT_POSTFIELDS]);
    }

    public function testSetBodyWithArrayAndEmptyContentType(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = ['key' => 'value'];

        $channel->setBody($body, '');

        $curlOptions = $channel->getCurlOptions();
        $this->assertEquals('{"key":"value"}', $curlOptions[CURLOPT_POSTFIELDS]);
    }

    public function testSetBodyJsonFailureHandling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $channel = new HttpChannel('http://example.com');

        // Create an array that will fail JSON encoding (infinite recursion)
        $body = [];
        $body['self'] = &$body;

        $channel->setBody($body, 'application/json');
    }

    public function testSetBodyCaseInsensitiveContentType(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = ['test' => 'data'];

        // Test uppercase content type
        $channel->setBody($body, 'APPLICATION/JSON');

        $curlOptions = $channel->getCurlOptions();
        $this->assertEquals('{"test":"data"}', $curlOptions[CURLOPT_POSTFIELDS]);
    }

    public function testSetBodyComplexFormData(): void
    {
        $channel = new HttpChannel('http://example.com');
        $body = [
            'simple' => 'value',
            'nested' => ['a' => 1, 'b' => 2],
            'special_chars' => 'hello world & more'
        ];

        $channel->setBody($body, 'application/x-www-form-urlencoded');

        $curlOptions = $channel->getCurlOptions();
        $expected = 'simple=value&nested%5Ba%5D=1&nested%5Bb%5D=2&special_chars=hello+world+%26+more';
        $this->assertEquals($expected, $curlOptions[CURLOPT_POSTFIELDS]);
    }
} 