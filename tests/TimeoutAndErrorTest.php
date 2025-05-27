<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Manager;
use Maurice\Multicurl\HttpChannel;
use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Helper\Stream;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for timeout and error handling
 */
#[Group('integration')]
class TimeoutAndErrorTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = 'http://' . ($_ENV['TEST_HTTP_SERVER'] ?? 'localhost:8080');
    }

    public function testConnectionTimeout(): void
    {
        $manager = new Manager(1);
        $timeouts = [];
        $results = [];
        $errors = [];

        // Use a non-routable IP to trigger connection timeout
        // 10.255.255.1 is a commonly used non-routable address for testing
        $channel = HttpChannel::create('http://10.255.255.1:80/test');
        $channel->setConnectionTimeout(500); // 500ms connection timeout
        $channel->setTimeout(2000); // 2s total timeout to ensure connection timeout triggers first
        
        $channel->setOnTimeoutCallback(function($channel, $timeoutType, $elapsedMS, $manager) use (&$timeouts) {
            $timeouts[] = [
                'type' => $timeoutType,
                'elapsed' => $elapsedMS
            ];
        });
        
        $channel->setOnReadyCallback(function($channel, $info, Stream $stream, $manager) use (&$results) {
            $content = $stream->consume();
            $results[] = ['status' => $info['http_code'], 'content' => $content];
        });
        
        $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$errors) {
            $errors[] = ['message' => $message, 'code' => $errno];
        });

        $manager->addChannel($channel);
        $manager->run();

        // Should get connection timeout
        $this->assertCount(1, $timeouts);
        $this->assertEquals(Channel::TIMEOUT_CONNECTION, $timeouts[0]['type']);
        $this->assertGreaterThanOrEqual(450, $timeouts[0]['elapsed']); // At least close to our timeout
        $this->assertLessThan(1000, $timeouts[0]['elapsed']); // But not the total timeout
        $this->assertCount(0, $results);
        $this->assertCount(0, $errors);
    }

    public function testTotalTimeout(): void
    {
        $manager = new Manager(1);
        $timeouts = [];
        $results = [];
        $errors = [];

        // Use the test server with a long delay endpoint
        $channel = HttpChannel::create($this->baseUrl . '/delay/2'); // 2 second delay
        $channel->setTimeout(500); // 500ms total timeout (shorter than delay)
        $channel->setConnectionTimeout(5000); // Long connection timeout to ensure total timeout triggers
        
        $channel->setOnTimeoutCallback(function($channel, $timeoutType, $elapsedMS, $manager) use (&$timeouts) {
            $timeouts[] = [
                'type' => $timeoutType,
                'elapsed' => $elapsedMS
            ];
        });
        
        $channel->setOnReadyCallback(function($channel, $info, Stream $stream, $manager) use (&$results) {
            $content = $stream->consume();
            $results[] = ['status' => $info['http_code'], 'content' => $content];
        });
        
        $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$errors) {
            $errors[] = ['message' => $message, 'code' => $errno];
        });

        $manager->addChannel($channel);
        $manager->run();

        // Should get total timeout
        $this->assertCount(1, $timeouts);
        $this->assertEquals(Channel::TIMEOUT_TOTAL, $timeouts[0]['type']);
        $this->assertGreaterThanOrEqual(450, $timeouts[0]['elapsed']); // At least close to our timeout
        $this->assertLessThan(1000, $timeouts[0]['elapsed']); // But well under the server delay
        $this->assertCount(0, $results);
        $this->assertCount(0, $errors);
    }

    public function testUnreachableHostError(): void
    {
        $manager = new Manager(1);
        $timeouts = [];
        $results = [];
        $errors = [];

        // Use an invalid hostname that should trigger a DNS resolution error
        $channel = HttpChannel::create('http://this-domain-absolutely-does-not-exist.invalid/test');
        $channel->setTimeout(5000); // Reasonable timeout
        $channel->setConnectionTimeout(5000);
        
        $channel->setOnTimeoutCallback(function($channel, $timeoutType, $elapsedMS, $manager) use (&$timeouts) {
            $timeouts[] = [
                'type' => $timeoutType,
                'elapsed' => $elapsedMS
            ];
        });
        
        $channel->setOnReadyCallback(function($channel, $info, Stream $stream, $manager) use (&$results) {
            $content = $stream->consume();
            $results[] = ['status' => $info['http_code'], 'content' => $content];
        });
        
        $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$errors) {
            $errors[] = [
                'message' => $message,
                'code' => $errno,
                'url' => $channel->getURL()
            ];
        });

        $manager->addChannel($channel);
        $manager->run();

        // Should get an error (DNS resolution failure)
        $this->assertCount(1, $errors);
        $this->assertCount(0, $timeouts);
        $this->assertCount(0, $results);
        
        // Error should be a DNS resolution error (CURLE_COULDNT_RESOLVE_HOST = 6)
        $this->assertEquals(6, $errors[0]['code']);
        $this->assertStringContainsString('resolve', strtolower($errors[0]['message']));
        $this->assertEquals('http://this-domain-absolutely-does-not-exist.invalid/test', $errors[0]['url']);
    }

    public function testMultipleTimeoutTypes(): void
    {
        $manager = new Manager(3); // Allow multiple concurrent requests
        $timeouts = [];
        $results = [];
        $errors = [];

        // Channel 1: Connection timeout
        $channel1 = HttpChannel::create('http://10.255.255.1:80/test');
        $channel1->setConnectionTimeout(300);
        $channel1->setTimeout(2000);
        
        // Channel 2: Total timeout  
        $channel2 = HttpChannel::create($this->baseUrl . '/delay/2');
        $channel2->setTimeout(300);
        $channel2->setConnectionTimeout(5000);
        
        // Channel 3: DNS error
        $channel3 = HttpChannel::create('http://nonexistent.invalid/test');
        $channel3->setTimeout(5000);
        $channel3->setConnectionTimeout(5000);

        foreach ([$channel1, $channel2, $channel3] as $i => $channel) {
            $channelId = $i + 1;
            
            $channel->setOnTimeoutCallback(function($channel, $timeoutType, $elapsedMS, $manager) use (&$timeouts, $channelId) {
                $timeouts[$channelId] = [
                    'type' => $timeoutType,
                    'elapsed' => $elapsedMS
                ];
            });
            
            $channel->setOnReadyCallback(function($channel, $info, Stream $stream, $manager) use (&$results, $channelId) {
                $content = $stream->consume();
                $results[$channelId] = ['status' => $info['http_code']];
            });
            
            $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$errors, $channelId) {
                $errors[$channelId] = ['message' => $message, 'code' => $errno];
            });

            $manager->addChannel($channel);
        }

        $manager->run();

        // Channel 1 should have connection timeout
        $this->assertArrayHasKey(1, $timeouts);
        $this->assertEquals(Channel::TIMEOUT_CONNECTION, $timeouts[1]['type']);
        
        // Channel 2 should have total timeout
        $this->assertArrayHasKey(2, $timeouts);
        $this->assertEquals(Channel::TIMEOUT_TOTAL, $timeouts[2]['type']);
        
        // Channel 3 should have DNS error
        $this->assertArrayHasKey(3, $errors);
        $this->assertEquals(6, $errors[3]['code']); // CURLE_COULDNT_RESOLVE_HOST
        
        // No successful results
        $this->assertCount(0, $results);
    }
} 