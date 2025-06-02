<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests\Integration;

use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Manager;
use Maurice\Multicurl\SseChannel;
use Maurice\Multicurl\Sse\SseEvent;
use PHPUnit\Framework\TestCase;

class SseIntegrationTest extends TestCase
{
    private string $httpbinBaseUrl = 'http://httpbin:8080';

    protected function setUp(): void
    {
        // Check if httpbin is available
        $ch = curl_init($this->httpbinBaseUrl . '/status/200');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->markTestSkipped(
                'httpbin service not available at ' . $this->httpbinBaseUrl . 
                ' (needed for SSE integration tests). Started it? (e.g., docker run -p 8080:80 kennethreitz/httpbin)'
            );
        }
    }

    public function testOnReadyCallbackCalledWhenServerEndsStream(): void
    {
        $manager = new Manager();
        $onReadyCalled = false;
        $eventReceived = false;

        // httpbin /sse endpoint sends SSE events.
        $sseChannel = new SseChannel($this->httpbinBaseUrl . '/sse?count=1'); // Sends 1 event then closes
        $sseChannel->setTimeout(5000);

        $sseChannel->setOnEventCallback(function (SseEvent $event, SseChannel $channel) use (&$eventReceived): bool {
            $eventReceived = true;
            // We don't need to do anything else with the event for this test
            return true;
        });

        $sseChannel->setOnReadyCallback(function () use (&$onReadyCalled) {
            $onReadyCalled = true;
        });

        $sseChannel->setOnErrorCallback(function (Channel $channel, string $message, int $errno, array $info, Manager $manager): void {
            $this->fail("onErrorCallback was called: {$message} ({$errno})");
        });

        $manager->addChannel($sseChannel);
        $manager->run();

        $this->assertTrue($eventReceived, 'An SSE event should have been received.');
        $this->assertTrue($onReadyCalled, 'onReadyCallback should be called when the server ends the SSE stream.');
    }

    public function testOnReadyCallbackCalledWhenUserTerminatesStream(): void
    {
        $manager = new Manager();
        $onReadyCalled = false;
        $eventReceived = false;
        $maxEvents = 1;
        $eventCount = 0;

        // httpbin /sse endpoint sends n events. We'll ask for more than we process.
        $sseChannel = new SseChannel($this->httpbinBaseUrl . '/sse?count=3');
        $sseChannel->setTimeout(5000);

        $sseChannel->setOnEventCallback(function (SseEvent $event, SseChannel $channel) use (&$eventReceived, &$eventCount, $maxEvents): bool {
            $eventReceived = true;
            $eventCount++;
            
            //phpstan-ignore-next-line
            return $eventCount < $maxEvents;
        });

        $sseChannel->setOnReadyCallback(function () use (&$onReadyCalled) {
            $onReadyCalled = true;
        });

        $sseChannel->setOnErrorCallback(function (Channel $channel, string $message, int $errno, array $info) {
            // With the Manager modification, CURLE_WRITE_ERROR for aborted streams should now trigger onReady.
            // If any other error occurs, it's a failure.
             if ($errno !== CURLE_WRITE_ERROR && !$channel->isStreamAborted()) { // Expected if stream is aborted by user
                $this->fail("onErrorCallback was called unexpectedly: {$message} ({$errno})");
             }
        });

        $manager->addChannel($sseChannel);
        $manager->run();

        $this->assertTrue($eventReceived, 'At least one SSE event should have been received before termination.');
        $this->assertEquals($maxEvents, $eventCount, 'Exactly maxEvents should have been processed.');
        $this->assertTrue($onReadyCalled, 'onReadyCallback should be called when the user terminates the SSE stream.');
    }
} 