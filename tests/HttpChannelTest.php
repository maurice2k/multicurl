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

    public function testGenerateCurlCommandWithMaximumOptions(): void
    {
        // Create a channel with a complex URL that needs escaping
        $channel = new HttpChannel('https://api.example.com/endpoint?param=value&special="chars"');
        
        // Set HTTP method to POST
        $channel->setMethod(HttpChannel::METHOD_POST);
        
        // Set complex JSON body
        $body = [
            'username' => 'test@example.com',
            'password' => 'secret"password\'with$pecial&chars',
            'data' => [
                'nested' => true,
                'number' => 42,
                'array' => [1, 2, 3]
            ]
        ];
        $channel->setBody($body, 'application/json');
        
        // Set multiple headers including ones with special characters
        $channel->setHeader('User-Agent', 'MyApp/1.0 (Special "Chars" & More)');
        $channel->setHeader('Authorization', 'Bearer token123with"quotes');
        $channel->setHeader('X-Custom-Header', 'value with spaces & symbols');
        $channel->setHeader('Accept', 'application/json, text/plain');
        
        // Set HTTP version
        $channel->setHttpVersion(HttpChannel::HTTP_2_0);
        
        // Set basic authentication
        $channel->setBasicAuth('user"name', 'pass"word');
        
        // Set follow redirects with custom max redirects
        $channel->setFollowRedirects(true, 5);
        
        // Set cookie jar file
        $channel->setCookieJarFile('/tmp/cookies.txt');
        
        // Set various timeouts
        $channel->setTimeout(30000); // 30 seconds
        $channel->setConnectionTimeout(5000); // 5 seconds
        
        // Set custom curl options for edge cases
        $channel->setCurlOption(CURLOPT_TIMEOUT, 25); // Test both TIMEOUT and TIMEOUT_MS
        $channel->setCurlOption(CURLOPT_CONNECTTIMEOUT, 3); // Test both CONNECTTIMEOUT and CONNECTTIMEOUT_MS
        
        // Generate the curl command
        $command = $channel->generateCurlCommand();
        
        // Assert URL is properly escaped
        $this->assertStringContainsString("'https://api.example.com/endpoint?param=value&special=\"chars\"'", $command);
        
        // Assert POST method
        $this->assertStringContainsString('-X POST', $command);
        
        // Assert JSON body is present (the exact escaping may vary due to shell escaping)
        $this->assertStringContainsString('-d ', $command);
        $this->assertStringContainsString('"username":"test@example.com"', $command);
        $this->assertStringContainsString('"nested":true', $command);
        
        // Assert headers are properly escaped
        $this->assertStringContainsString("-H 'user-agent: MyApp/1.0 (Special \"Chars\" & More)'", $command);
        $this->assertStringContainsString("-H 'authorization: Bearer token123with\"quotes'", $command);
        $this->assertStringContainsString("-H 'x-custom-header: value with spaces & symbols'", $command);
        $this->assertStringContainsString("-H 'accept: application/json, text/plain'", $command);
        $this->assertStringContainsString("-H 'content-type: application/json'", $command);
        
        // Assert HTTP version
        $this->assertStringContainsString('--http2', $command);
        
        // Assert basic auth (should use the newer setCurlOption value, not setBasicAuth)
        $this->assertStringContainsString("-u 'user\"name:pass\"word'", $command);
        
        // Assert follow redirects
        $this->assertStringContainsString('-L', $command);
        $this->assertStringContainsString('--max-redirs \'5\'', $command);
        
        // Assert cookie jar
        $this->assertStringContainsString("-c '/tmp/cookies.txt'", $command);
        
        // Assert timeouts (should prefer the explicit TIMEOUT/CONNECTTIMEOUT over the _MS versions)
        $this->assertStringContainsString('--max-time \'25\'', $command);
        $this->assertStringContainsString('--connect-timeout \'3\'', $command);
        
        // Ensure the command starts with 'curl'
        $this->assertStringStartsWith('curl ', $command);
        
        // Ensure there are no extra spaces at the end
        $this->assertEquals($command, trim($command));
    }

    public function testGenerateCurlCommandWithGETAndBody(): void
    {
        $channel = new HttpChannel('https://example.com/search');
        
        // Set method to GET but with a body (should use CUSTOMREQUEST)
        $channel->setMethod(HttpChannel::METHOD_GET);
        $channel->setBody('{"query": "search term"}', 'application/json');
        
        $command = $channel->generateCurlCommand();
        
        // Should not contain -X POST since method is GET
        $this->assertStringNotContainsString('-X POST', $command);
        // Should not contain -X GET either (GET is default)
        $this->assertStringNotContainsString('-X GET', $command);
        // Should contain the body
        $this->assertStringContainsString("-d '{\"query\": \"search term\"}'", $command);
    }

    public function testGenerateCurlCommandWithHTTP11(): void
    {
        $channel = new HttpChannel('https://example.com');
        $channel->setHttpVersion(HttpChannel::HTTP_1_1);
        
        $command = $channel->generateCurlCommand();
        
        $this->assertStringContainsString('--http1.1', $command);
        $this->assertStringNotContainsString('--http2', $command);
    }

    public function testGenerateCurlCommandWithMinimalOptions(): void
    {
        $channel = new HttpChannel('https://example.com');
        
        $command = $channel->generateCurlCommand();
        
        // Should only contain URL
        $this->assertEquals("curl 'https://example.com'", $command);
    }

    public function testGenerateCurlCommandWithCookieFileOnly(): void
    {
        $channel = new HttpChannel('https://example.com');
        
        // Set only cookie file (not jar)
        $channel->setCurlOption(CURLOPT_COOKIEFILE, '/path/to/cookies.txt');
        
        $command = $channel->generateCurlCommand();
        
        $this->assertStringContainsString("-b '/path/to/cookies.txt'", $command);
        $this->assertStringNotContainsString('-c ', $command); // Should not contain cookie jar option
    }

    public function testGenerateCurlCommandWithEmptyBody(): void
    {
        $channel = new HttpChannel('https://example.com');
        $channel->setMethod(HttpChannel::METHOD_POST);
        $channel->setBody(''); // Empty body
        
        $command = $channel->generateCurlCommand();
        
        $this->assertStringContainsString('-X POST', $command);
        $this->assertStringNotContainsString('-d ', $command); // Should not contain data option for empty body
    }
} 