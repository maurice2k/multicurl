<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Manager;
use Maurice\Multicurl\Channel;
use PHPUnit\Framework\TestCase;

class ManagerTest extends TestCase
{
    /**
     * Test constructor and maxConcurrency setting
     */
    public function testConstructor(): void
    {
        $manager = new Manager();
        $this->assertInstanceOf(Manager::class, $manager);
        
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('maxConcurrency');
        $property->setAccessible(true);
        $this->assertEquals(10, $property->getValue($manager));
        
        $manager = new Manager(20);
        $this->assertEquals(20, $property->getValue($manager));
    }
    
    /**
     * Test setting max concurrency
     */
    public function testSetMaxConcurrency(): void
    {
        $manager = new Manager();
        
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('maxConcurrency');
        $property->setAccessible(true);
        
        $manager->setMaxConcurrency(5);
        $this->assertEquals(5, $property->getValue($manager));
        
        // Test that setting concurrency < 1 results in value 1
        $manager->setMaxConcurrency(0);
        $this->assertEquals(1, $property->getValue($manager));
    }
    
    /**
     * Test adding a channel
     */
    public function testAddChannel(): void
    {
        $manager = new Manager();
        $channel = $this->createMock(Channel::class);
        
        $reflection = new \ReflectionClass($manager);
        $channelQueueProperty = $reflection->getProperty('channelQueue');
        $channelQueueProperty->setAccessible(true);
        
        // Test normal add
        $manager->addChannel($channel);
        $this->assertCount(1, $channelQueueProperty->getValue($manager));
        $this->assertSame($channel, $channelQueueProperty->getValue($manager)[0]);
        
        // Reset queue
        $channelQueueProperty->setValue($manager, []);
        
        // Test unshift
        $channel2 = $this->createMock(Channel::class);
        $manager->addChannel($channel, false);
        $manager->addChannel($channel2, true);
        $channelQueue = $channelQueueProperty->getValue($manager);
        $this->assertCount(2, $channelQueue);
        $this->assertSame($channel2, $channelQueue[0]);
        $this->assertSame($channel, $channelQueue[1]);
    }
    
    /**
     * Test adding a delayed channel
     */
    public function testAddChannelWithDelay(): void
    {
        $manager = new Manager();
        $channel = $this->createMock(Channel::class);
        
        $reflection = new \ReflectionClass($manager);
        $delayQueueProperty = $reflection->getProperty('delayQueue');
        $delayQueueProperty->setAccessible(true);
        $delayQueueSortedProperty = $reflection->getProperty('delayQueueSorted');
        $delayQueueSortedProperty->setAccessible(true);
        
        // Test delay
        $manager->addChannel($channel, false, 0.5);
        $delayQueue = $delayQueueProperty->getValue($manager);
        $this->assertCount(1, $delayQueue);
        $this->assertSame($channel, $delayQueue[0][0]);
        $this->assertFalse($delayQueue[0][1]); // unshift flag
        $this->assertGreaterThan(microtime(true), $delayQueue[0][2]); // delay timestamp
        $this->assertFalse($delayQueueSortedProperty->getValue($manager));
    }
    
    /**
     * Test setting refill callback
     */
    public function testSetRefillCallback(): void
    {
        $manager = new Manager();
        $callback = function($queueSize, $maxConcurrency) {
            return true;
        };
        
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('refillCallback');
        $property->setAccessible(true);
        
        $manager->setRefillCallback($callback);
        $this->assertSame($callback, $property->getValue($manager));
    }
    
    /**
     * Test context info trait integration
     */
    public function testContextInfo(): void
    {
        $manager = new Manager();
        $this->assertNull($manager->getContext());
        
        $context = ['test' => 'value'];
        $manager->setContext($context);
        $this->assertSame($context, $manager->getContext());
    }
    
    /**
     * Test queue low watermark callback
     */
    public function testQueueLowWatermarkCallback(): void
    {
        $manager = new Manager(5);
        
        $callbackCalled = false;
        $queueSizeFromCallback = null;
        $maxConcurrencyFromCallback = null;
        
        $callback = function($queueSize, $maxConcurrency) use (&$callbackCalled, &$queueSizeFromCallback, &$maxConcurrencyFromCallback) {
            $callbackCalled = true;
            $queueSizeFromCallback = $queueSize;
            $maxConcurrencyFromCallback = $maxConcurrency;
        };
        
        $manager->setRefillCallback($callback);
        
        $reflection = new \ReflectionClass($manager);
        $onQueueLowWatermark = $reflection->getMethod('onQueueLowWatermark');
        $onQueueLowWatermark->setAccessible(true);
        $channelQueueProperty = $reflection->getProperty('channelQueue');
        $channelQueueProperty->setAccessible(true);
        
        // Add some channels to the queue
        $channelQueueProperty->setValue($manager, [
            $this->createMock(Channel::class),
            $this->createMock(Channel::class),
            $this->createMock(Channel::class)
        ]);
        
        $onQueueLowWatermark->invoke($manager);
        
        $this->assertTrue($callbackCalled);
        $this->assertEquals(3, $queueSizeFromCallback);
        $this->assertEquals(5, $maxConcurrencyFromCallback);
    }
} 