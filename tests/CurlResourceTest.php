<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Manager;
use Maurice\Multicurl\Channel;
use PHPUnit\Framework\TestCase;

class CurlResourceTest extends TestCase
{
    /**
     * Test curl handle resource creation from Channel
     */
    public function testCreateCurlHandleFromChannel(): void
    {
        $manager = new Manager();
        
        // Create a channel with specific curl options
        $channel = new Channel();
        $channel->setCurlOption(CURLOPT_URL, 'https://example.com');
        $channel->setCurlOption(CURLOPT_TIMEOUT_MS, 5000);
        
        // Access private method
        $reflection = new \ReflectionClass($manager);
        $createCurlHandle = $reflection->getMethod('createCurlHandleFromChannel');
        $createCurlHandle->setAccessible(true);
        
        // Call method
        $curlHandle = $createCurlHandle->invoke($manager, $channel);
        
        // Verify it's a curl handle
        $this->assertInstanceOf(\CurlHandle::class, $curlHandle);
        
        // Verify some options were set
        $info = curl_getinfo($curlHandle);
        $this->assertEquals('https://example.com', $info['url']);
        
        // Clean up
        curl_close($curlHandle);
    }
    
    /**
     * Test handle identifier conversion
     */
    public function testToHandleIdentifier(): void
    {
        // Create a temporary curl handle
        $ch = curl_init();
        
        // Access static method
        $reflection = new \ReflectionClass(Manager::class);
        $toHandleIdentifier = $reflection->getMethod('toHandleIdentifier');
        $toHandleIdentifier->setAccessible(true);
        
        // Get identifier
        $identifier = $toHandleIdentifier->invoke(null, $ch);
        
        // Verify it's a non-zero integer
        $this->assertIsInt($identifier);
        $this->assertGreaterThan(0, $identifier);
        
        // If we get another identifier from the same handle, it should match
        $identifier2 = $toHandleIdentifier->invoke(null, $ch);
        $this->assertEquals($identifier, $identifier2);
        
        // Clean up
        curl_close($ch);
    }
    
    /**
     * Test resourceChannelLookup functionality
     */
    public function testResourceChannelLookup(): void
    {
        $manager = new Manager();
        
        // Create a channel
        $channel = new Channel();
        $channel->setCurlOption(CURLOPT_URL, 'https://example.com');
        
        // Access private methods/properties
        $reflection = new \ReflectionClass($manager);
        $createCurlHandle = $reflection->getMethod('createCurlHandleFromChannel');
        $createCurlHandle->setAccessible(true);
        $toHandleIdentifier = $reflection->getMethod('toHandleIdentifier');
        $toHandleIdentifier->setAccessible(true);
        $resourceChannelLookupProperty = $reflection->getProperty('resourceChannelLookup');
        $resourceChannelLookupProperty->setAccessible(true);
        
        // Create curl handle
        $ch = $createCurlHandle->invoke($manager, $channel);
        
        // Add to lookup
        $id = $toHandleIdentifier->invoke(null, $ch);
        $resourceChannelLookupProperty->setValue($manager, [$id => $channel]);
        
        // Test lookup
        $lookup = $resourceChannelLookupProperty->getValue($manager);
        $this->assertArrayHasKey($id, $lookup);
        $this->assertSame($channel, $lookup[$id]);
        
        // Clean up
        curl_close($ch);
    }
    
    /**
     * Test adding curl resources to multi-curl
     */
    public function testAddNCurlResourcesToMultiCurl(): void
    {
        $manager = new Manager();
        
        // Access private methods/properties
        $reflection = new \ReflectionClass($manager);
        $channelQueueProperty = $reflection->getProperty('channelQueue');
        $channelQueueProperty->setAccessible(true);
        $addNCurlResources = $reflection->getMethod('addNCurlResourcesToMultiCurl');
        $addNCurlResources->setAccessible(true);
        
        // Create channels
        $channel1 = new Channel();
        $channel1->setCurlOption(CURLOPT_URL, 'https://example.com/1');
        $channel2 = new Channel();
        $channel2->setCurlOption(CURLOPT_URL, 'https://example.com/2');
        
        // Add channels to queue
        $channelQueueProperty->setValue($manager, [$channel1, $channel2]);
        
        // Initialize mh property
        $mhProp = $reflection->getProperty('mh');
        $mhProp->setAccessible(true);
        $mhProp->setValue($manager, curl_multi_init());
        
        // Call method to add 1 resource
        $added = $addNCurlResources->invoke($manager, 1);
        
        // Verify the return value
        $this->assertEquals(1, $added);
        
        // Verify the channel queue now has only 1 item
        $this->assertCount(1, $channelQueueProperty->getValue($manager));
        
        // Clean up
        curl_multi_close($mhProp->getValue($manager));
    }
} 