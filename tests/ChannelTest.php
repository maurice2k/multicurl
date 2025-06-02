<?php
declare(strict_types = 1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Channel class
 */
class ChannelTest extends TestCase
{
    /**
     * Test the appendNextChannel method with a single channel
     */
    public function testAppendNextChannelSingle(): void
    {
        $channel1 = new Channel();
        $channel2 = new Channel();
        
        // Append channel2 to channel1
        $channel1->appendNextChannel($channel2);
        
        // Get the nextChannel from channel1
        $nextChannel = $channel1->popNextChannel();
        
        // Assert that the nextChannel is channel2
        $this->assertSame($channel2, $nextChannel);
        
        // Assert that channel1 no longer has a nextChannel
        $this->assertNull($channel1->popNextChannel());
    }
    
    /**
     * Test the appendNextChannel method with a chain of two channels
     */
    public function testAppendNextChannelToExistingNext(): void
    {
        $channel1 = new Channel();
        $channel2 = new Channel();
        $channel3 = new Channel();
        
        // Set channel2 as the nextChannel of channel1
        $channel1->setNextChannel($channel2);
        
        // Append channel3 to the chain
        $channel1->appendNextChannel($channel3);
        
        // Get the nextChannel from channel1
        $nextChannel = $channel1->popNextChannel();
        
        // Assert that the nextChannel is channel2
        $this->assertSame($channel2, $nextChannel);
        
        // Get the nextChannel from channel2
        $nextNextChannel = $channel2->popNextChannel();
        
        // Assert that the nextChannel of channel2 is channel3
        $this->assertSame($channel3, $nextNextChannel);
    }
    
    /**
     * Test the appendNextChannel method with a longer chain
     */
    public function testAppendNextChannelToLongerChain(): void
    {
        $channel1 = new Channel();
        $channel2 = new Channel();
        $channel3 = new Channel();
        $channel4 = new Channel();
        
        // Build a chain: channel1 -> channel2 -> channel3
        $channel1->setNextChannel($channel2);
        $channel2->setNextChannel($channel3);
        
        // Append channel4 to the chain
        $channel1->appendNextChannel($channel4);
        
        // Extract the chain
        $next1 = $channel1->popNextChannel();
        $next2 = $next1->popNextChannel();
        $next3 = $next2->popNextChannel();
        
        // Verify the chain order
        $this->assertSame($channel2, $next1);
        $this->assertSame($channel3, $next2);
        $this->assertSame($channel4, $next3);
        
        // Verify the end of the chain
        $this->assertNull($next3->popNextChannel());
    }
    
    /**
     * Test the integration of appendNextChannel with Manager execution
     */
    public function testAppendNextChannelExecution(): void
    {
        // Create a manager
        $manager = new Manager(1); // Only one concurrent channel to ensure order
        
        // Create test channels
        $channel1 = new Channel();
        $channel2 = new Channel();
        $channel3 = new Channel();
        
        // Set up execution tracking
        $executionOrder = [];
        
        // Configure channel callbacks
        $channel1->setOnReadyCallback(function (Channel $ch, array $info, $stream, Manager $mgr) use (&$executionOrder) {
            $executionOrder[] = 1;
        });
        
        $channel2->setOnReadyCallback(function (Channel $ch, array $info, $stream, Manager $mgr) use (&$executionOrder) {
            $executionOrder[] = 2;
        });
        
        $channel3->setOnReadyCallback(function (Channel $ch, array $info, $stream, Manager $mgr) use (&$executionOrder) {
            $executionOrder[] = 3;
        });
        
        // Set minimal curl options to make the channels "work" without actual network
        $channel1->setCurlOption(CURLOPT_URL, 'file:///dev/null');
        $channel2->setCurlOption(CURLOPT_URL, 'file:///dev/null');
        $channel3->setCurlOption(CURLOPT_URL, 'file:///dev/null');
        
        // Create the chain
        $channel1->setNextChannel($channel2);
        $channel1->appendNextChannel($channel3);
        
        // Add channel1 to the manager
        $manager->addChannel($channel1);
        
        // Run the manager
        $manager->run();
        
        // Check that all channels were executed in the correct order
        $this->assertEquals([1, 2, 3], $executionOrder);
    }
    
    /**
     * Test the beforeChannel with appendNextChannel integration
     */
    public function testBeforeChannelWithNextChannelChain(): void
    {
        // Create a manager
        $manager = new Manager(1); // Only one concurrent channel to ensure order
        
        // Create test channels
        $mainChannel = new Channel();
        $beforeChannel = new Channel();
        $nextChannel1 = new Channel();
        $nextChannel2 = new Channel();
        
        // Set up execution tracking
        $executionOrder = [];
        
        // Configure channel callbacks
        $beforeChannel->setOnReadyCallback(function (Channel $ch, array $info, $stream, Manager $mgr) use (&$executionOrder) {
            $executionOrder[] = 'before';
        });
        
        $mainChannel->setOnReadyCallback(function (Channel $ch, array $info, $stream, Manager $mgr) use (&$executionOrder) {
            $executionOrder[] = 'main';
        });
        
        $nextChannel1->setOnReadyCallback(function (Channel $ch, array $info, $stream, Manager $mgr) use (&$executionOrder) {
            $executionOrder[] = 'next1';
        });
        
        $nextChannel2->setOnReadyCallback(function (Channel $ch, array $info, $stream, Manager $mgr) use (&$executionOrder) {
            $executionOrder[] = 'next2';
        });
        
        // Set minimal curl options to make the channels "work" without actual network
        $beforeChannel->setCurlOption(CURLOPT_URL, 'file:///dev/null');
        $mainChannel->setCurlOption(CURLOPT_URL, 'file:///dev/null');
        $nextChannel1->setCurlOption(CURLOPT_URL, 'file:///dev/null');
        $nextChannel2->setCurlOption(CURLOPT_URL, 'file:///dev/null');
        
        // Set up the beforeChannel
        $mainChannel->setBeforeChannel($beforeChannel);
        
        // Create a chain of nextChannels on the beforeChannel
        $beforeChannel->setNextChannel($nextChannel1);
        $beforeChannel->appendNextChannel($nextChannel2);
        
        // Add the mainChannel to the manager
        $manager->addChannel($mainChannel);
        
        // Run the manager
        $manager->run();
        
        // Check that channels were executed in the correct order
        // The mainChannel should have been appended to the end of beforeChannel's chain
        $this->assertEquals(['before', 'next1', 'next2', 'main'], $executionOrder);
    }
} 