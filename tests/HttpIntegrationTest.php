<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Manager;
use Maurice\Multicurl\HttpChannel;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test with HTTP server in container
 */
#[Group('integration')]
class HttpIntegrationTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = 'http://' . ($_ENV['TEST_HTTP_SERVER'] ?? 'localhost:8080');
    }

    /**
     * Test that HTTP server is available
     */
    public function testHttpServerAvailable(): void
    {
        $ch = curl_init($this->baseUrl . '/status/200');
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 2000);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertEquals(200, $httpCode, 'HTTP server should be available');
    }

    public function testMultipleHttpRequests(): void
    {
        $manager = new Manager(3); // Set concurrency to 3
        
        $results = [];
        $errors = [];
        $timeouts = [];
        
        // Create 5 channels for testing
        for ($i = 1; $i <= 5; $i++) {
            $channel = HttpChannel::create($this->baseUrl . '/get?id=' . $i);
            $channel->setTimeout(5000); // 5 seconds timeout
            
            // Capture the ID for use in callbacks
            $channelId = $i;
            
            $channel->setOnReadyCallback(function($channel, $info, $content) use (&$results, $channelId) {
                $results[$channelId] = [
                    'status' => $info['http_code'],
                    'content' => $content
                ];
            });
            
            $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$errors, $channelId) {
                $errors[$channelId] = [
                    'message' => $message,
                    'code' => $errno
                ];
            });
            
            $channel->setOnTimeoutCallback(function($channel, $timeoutType, $elapsedMS, $manager) use (&$timeouts, $channelId) {
                $timeouts[$channelId] = [
                    'type' => $timeoutType,
                    'elapsed' => $elapsedMS
                ];
            });
            
            // Add to manager
            $manager->addChannel($channel);
        }
        
        // Run manager
        $manager->run();
        
        // Verify all channels completed successfully
        $this->assertCount(5, $results);
        $this->assertCount(0, $errors);
        $this->assertCount(0, $timeouts);
        
        // Verify each response contains the correct id
        foreach ($results as $id => $result) {
            $this->assertEquals(200, $result['status']);
            $data = json_decode($result['content'], true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('args', $data);
            $this->assertArrayHasKey('id', $data['args']);
            $this->assertEquals((string)$id, $data['args']['id']);
        }
    }

    public function testHttpErrorHandling(): void
    {
        $manager = new Manager(1);
        
        $errors = [];
        
        // Test 404 error
        $channel = HttpChannel::create($this->baseUrl . '/status/404');
        $channel->setTimeout(5000);
        
        $channel->setOnReadyCallback(function($channel, $info, $content) use (&$errors) {
            $errors[] = [
                'type' => 'ready',
                'status' => $info['http_code'],
                'content' => $content
            ];
        });
        
        $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$errors) {
            $errors[] = [
                'type' => 'error',
                'message' => $message,
                'code' => $errno
            ];
        });
        
        $manager->addChannel($channel);
        $manager->run();
        
        // Should get a ready callback with 404 status, not an error
        $this->assertCount(1, $errors);
        $this->assertEquals('ready', $errors[0]['type']);
        $this->assertEquals(404, $errors[0]['status']);
    }

    public function testPostRequest(): void
    {
        $manager = new Manager(1);
        $results = [];

        $testData = ['user' => [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'age' => 30,
            'address' => [
                'street' => '123 Main St',
                'city' => 'Anytown',
                'state' => 'CA',
            ]
        ]];

        $channel = HttpChannel::create($this->baseUrl . '/post', HttpChannel::METHOD_POST, $testData);
        $channel->setTimeout(5000);
        
        $channel->setOnReadyCallback(function($channel, $info, $content) use (&$results) {
            $results[] = [
                'content' => $content,
                'info' => $info,
            ];
        });
        
        $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$results) {
            $results[] = [
                'content' => null,
                'info' => $info,
                'message' => $message,
                'errno' => $errno,
            ];
        });
        $manager->addChannel($channel);
        $manager->run();
        
        $this->assertCount(1, $results);

        $data = json_decode($results[0]['content'], true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals($testData, json_decode($data['data'], true));
    }

    public function testConcurrentRequests(): void
    {
        $manager = new Manager(10); // Concurrency of 2
        
        $startTime = microtime(true);
        $processingTimes = [];
        $errors = [];
        
        // Create 4 channels with delays to test concurrency
        for ($i = 1; $i <= 10; $i++) {
            $delay = 2; // 1 second delay per request
            $channel = HttpChannel::create($this->baseUrl . '/delay/' . $delay);
            $channel->setTimeout(2500);
            
            $channelId = $i;
            
            $channel->setOnReadyCallback(function($channel, $info, $content) use (&$processingTimes, $startTime, $channelId) {
                $processingTimes[$channelId] = microtime(true) - $startTime;
            });
            
            $channel->setOnErrorCallback(function($channel, $message, $errno, $info) use (&$errors, $channelId) {
                $errors[$channelId] = [
                    'message' => $message,
                    'code' => $errno,
                    'info' => $info,
                ];
            });
            
            $manager->addChannel($channel);
        }
        
        $manager->run();
        $totalTime = microtime(true) - $startTime;
        
        // With concurrency of 10 and 10 requests of 2 seconds each,
        // total time should be around 2 seconds
        $this->assertLessThan(2.5, $totalTime, 'Concurrent execution should be faster than sequential');
        $this->assertCount(10, $processingTimes);
        $this->assertCount(0, $errors);
    }
} 