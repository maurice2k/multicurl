<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Manager;
use Maurice\Multicurl\Channel;
use PHPUnit\Framework\TestCase;

class DelayQueueTest extends TestCase
{
    /**
     * Test that processDelayQueue properly sorts the queue
     */
    public function testDelayQueueSorting(): void
    {
        $manager = new Manager();

        $reflection = new \ReflectionClass($manager);
        $delayQueueProperty = $reflection->getProperty('delayQueue');
        $delayQueueProperty->setAccessible(true);
        $delayQueueSortedProperty = $reflection->getProperty('delayQueueSorted');
        $delayQueueSortedProperty->setAccessible(true);
        $processDelayQueue = $reflection->getMethod('processDelayQueue');
        $processDelayQueue->setAccessible(true);

        // Create mock channels
        $channel1 = $this->createMock(Channel::class);
        $channel2 = $this->createMock(Channel::class);
        $channel3 = $this->createMock(Channel::class);

        // Set up delay queue with unsorted timestamps
        $now = microtime(true);
        $delayQueueProperty->setValue($manager, [
            [$channel2, false, $now + 0.3],
            [$channel1, false, $now + 0.1],
            [$channel3, false, $now + 0.5]
        ]);
        $delayQueueSortedProperty->setValue($manager, false);

        // Process delay queue
        $delay = $processDelayQueue->invoke($manager);

        // Verify the queue was sorted (channel1 should be first)
        $delayQueue = $delayQueueProperty->getValue($manager);
        $this->assertCount(3, $delayQueue);
        $this->assertSame($channel1, $delayQueue[0][0]);
        $this->assertSame($channel2, $delayQueue[1][0]);
        $this->assertSame($channel3, $delayQueue[2][0]);
        $this->assertTrue($delayQueueSortedProperty->getValue($manager));

        // Delay should be approximately 0.1s in microseconds
        $this->assertGreaterThan(0, $delay);
        $this->assertLessThan(500000, $delay); // Less than 0.5s
    }

    /**
     * Test that processDelayQueue moves due channels to the standard queue
     */
    public function testDelayQueueProcessingDueChannels(): void
    {
        $manager = new Manager();

        $reflection = new \ReflectionClass($manager);
        $delayQueueProperty = $reflection->getProperty('delayQueue');
        $delayQueueProperty->setAccessible(true);
        $channelQueueProperty = $reflection->getProperty('channelQueue');
        $channelQueueProperty->setAccessible(true);
        $processDelayQueue = $reflection->getMethod('processDelayQueue');
        $processDelayQueue->setAccessible(true);

        // Create mock channels
        $dueChannel = $this->createMock(Channel::class);
        $futureChannel = $this->createMock(Channel::class);

        // Set up delay queue with one due timestamp and one future timestamp
        $now = microtime(true);
        $delayQueueProperty->setValue($manager, [
            [$dueChannel, false, $now - 0.1], // Already due
            [$futureChannel, false, $now + 0.5] // Due in the future
        ]);

        // Process delay queue
        $processDelayQueue->invoke($manager);

        // Verify due channel was moved to the channel queue
        $delayQueue = $delayQueueProperty->getValue($manager);
        $channelQueue = $channelQueueProperty->getValue($manager);

        $this->assertCount(1, $delayQueue); // Only future channel remains
        $this->assertSame($futureChannel, $delayQueue[0][0]);

        $this->assertCount(1, $channelQueue); // Due channel was added
        $this->assertSame($dueChannel, $channelQueue[0]);
    }

    /**
     * Test that processDelayQueue returns null when delay queue is empty
     */
    public function testEmptyDelayQueue(): void
    {
        $manager = new Manager();

        $reflection = new \ReflectionClass($manager);
        $processDelayQueue = $reflection->getMethod('processDelayQueue');
        $processDelayQueue->setAccessible(true);

        $result = $processDelayQueue->invoke($manager);
        $this->assertNull($result);
    }

    /**
     * Test that unshift flag works when processing delay queue
     */
    public function testDelayQueueWithUnshiftFlag(): void
    {
        $manager = new Manager();

        $reflection = new \ReflectionClass($manager);
        $delayQueueProperty = $reflection->getProperty('delayQueue');
        $delayQueueProperty->setAccessible(true);
        $channelQueueProperty = $reflection->getProperty('channelQueue');
        $channelQueueProperty->setAccessible(true);
        $processDelayQueue = $reflection->getMethod('processDelayQueue');
        $processDelayQueue->setAccessible(true);

        // Create mock channels
        $channel1 = $this->createMock(Channel::class);
        $channel2 = $this->createMock(Channel::class);

        // Add a channel to the queue first
        $existingChannel = $this->createMock(Channel::class);
        $channelQueueProperty->setValue($manager, [$existingChannel]);

        // Set up delay queue with two due channels, one with unshift=true
        $now = microtime(true);
        $delayQueueProperty->setValue($manager, [
            [$channel1, true, $now - 0.1],  // With unshift flag
            [$channel2, false, $now - 0.1]  // Without unshift flag
        ]);

        // Process delay queue
        $processDelayQueue->invoke($manager);

        // Verify channels were added in the correct order
        $channelQueue = $channelQueueProperty->getValue($manager);
        $this->assertCount(3, $channelQueue);

        // channel1 should be at the beginning (unshift=true)
        $this->assertSame($channel1, $channelQueue[0]);
        // existingChannel should be in the middle
        $this->assertSame($existingChannel, $channelQueue[1]);
        // channel2 should be at the end (unshift=false)
        $this->assertSame($channel2, $channelQueue[2]);
    }
} 