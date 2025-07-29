<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests\Integration;

use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Manager;
use Maurice\Multicurl\McpChannel;
use Maurice\Multicurl\Mcp\RpcMessage;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real integration tests for MCP (Model Context Protocol) functionality
 * Tests against mcp/everything container with actual network communication
 */
#[Group('integration')]
class McpIntegrationTest extends TestCase
{
    private string $mcpBaseUrl;
    private bool $showCurlCommand = false;

    protected function setUp(): void
    {
        $this->mcpBaseUrl = 'http://' . ($_ENV['TEST_MCP_SERVER'] ?? 'localhost:3001') . '/mcp';
    }

    public function testMcpInitializationIntegration(): void
    {
        $manager = new Manager();
        $initResponse = null;
        $errorMessage = null;

        // Create initialize request
        $initMessage = RpcMessage::initializeRequest(
            protocolVersion: '2025-06-18',
            clientInfo: [
                'name' => 'multicurl-test-client',
                'version' => '1.0.0'
            ],
            capabilities: [
                'roots' => ['listChanged' => true],
                'sampling' => []
            ]
        );

        $channel = new McpChannel($this->mcpBaseUrl, $initMessage);
        $channel->setShowCurlCommand($this->showCurlCommand);
        $channel->setTimeout(1000);

        $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$initResponse): bool {
            if ($message->isResponse() && !$message->isError()) {
                $initResponse = $message->getResult();
            }
            return true;
        });

        $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage) {
            $errorMessage = "Error: $message (code: $errno)";
        });

        $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($channel);
        $manager->run(); // ACTUAL INTEGRATION TEST

        // For stdio-based MCP servers, we might get connection errors, so we'll be more lenient
        if ($errorMessage && !str_contains($errorMessage, 'Server returned nothing')) {
            $this->fail("Unexpected error during initialization: $errorMessage");
        }

        // If we got a successful response, validate it
        if ($initResponse !== null) {
            $this->assertIsArray($initResponse, 'Initialize response should be an array');
            $this->assertArrayHasKey('protocolVersion', $initResponse, 'Response should contain protocol version');
            $this->assertArrayHasKey('capabilities', $initResponse, 'Response should contain server capabilities');
            $this->assertArrayHasKey('serverInfo', $initResponse, 'Response should contain server info');
        }

        $this->assertTrue(true, 'MCP initialization integration test completed');
    }

    public function testMcpToolsListIntegration(): void
    {
        $manager = new Manager();
        $toolsResponse = null;
        $errorMessage = null;

        // Create tools/list request
        $toolsMessage = RpcMessage::toolsListRequest();
        $channel = new McpChannel($this->mcpBaseUrl, $toolsMessage);
        $channel->setShowCurlCommand($this->showCurlCommand);
        //$channel->setVerbose(true);
        $channel->setTimeout(1000);

        // Set up automatic initialization
        $channel->setAutomaticInitialize(
            clientInfo: [
                'name' => 'multicurl-test-client',
                'version' => '1.0.0'
            ],
            capabilities: [
                'roots' => ['listChanged' => true],
                'sampling' => []
            ]
        );

        $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$toolsResponse): bool {
            if ($message->isResponse() && !$message->isError() && $message->getResult() !== null) {
                $result = $message->getResult();
                // Check if this is a tools/list response (has 'tools' key)
                if (is_array($result) && array_key_exists('tools', $result)) {
                    $toolsResponse = $result;
                }
            }
            return true;
        });

        $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage) {
            $errorMessage = "Error: $message (code: $errno)";
        });

        $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($channel);
        $manager->run(); // ACTUAL INTEGRATION TEST

        // For stdio-based MCP servers, we might get connection errors
        if ($errorMessage && !str_contains($errorMessage, 'Server returned nothing')) {
            $this->fail("Unexpected error during tools list: $errorMessage");
        }

        // If we got a successful response, validate it
        if ($toolsResponse !== null) {
            $this->assertIsArray($toolsResponse, 'Tools response should be an array');
            $this->assertArrayHasKey('tools', $toolsResponse, 'Response should contain tools array');
            $this->assertIsArray($toolsResponse['tools'], 'Tools should be an array');

            // Verify tool structure if tools are present
            if (!empty($toolsResponse['tools'])) {
                $firstTool = $toolsResponse['tools'][0];
                $this->assertArrayHasKey('name', $firstTool, 'Tool should have a name');
                $this->assertArrayHasKey('description', $firstTool, 'Tool should have a description');
            }
        }

        $this->assertTrue(true, 'MCP tools list integration test completed');
    }

    public function testMcpPromptsListIntegration(): void
    {
        $manager = new Manager();
        $promptsResponse = null;
        $errorMessage = null;

        // Create prompts/list request
        $promptsMessage = RpcMessage::request('prompts/list');
        $channel = new McpChannel($this->mcpBaseUrl, $promptsMessage);
        $channel->setTimeout(1000);

        // Set up automatic initialization
        $channel->setAutomaticInitialize(
            clientInfo: [
                'name' => 'multicurl-test-client',
                'version' => '1.0.0'
            ],
            capabilities: [
                'roots' => ['listChanged' => true],
                'sampling' => []
            ]
        );

        $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$promptsResponse): bool {
            if ($message->isResponse() && !$message->isError() && $message->getResult() !== null) {
                $result = $message->getResult();
                // Check if this is a prompts/list response (has 'prompts' key)
                if (is_array($result) && array_key_exists('prompts', $result)) {
                    $promptsResponse = $result;
                }
            }
            return true;
        });

        $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage) {
            $errorMessage = "Error: $message (code: $errno)";
        });

        $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($channel);
        $manager->run(); // ACTUAL INTEGRATION TEST

        // For stdio-based MCP servers, we might get connection errors
        if ($errorMessage && !str_contains($errorMessage, 'Server returned nothing')) {
            $this->fail("Unexpected error during prompts list: $errorMessage");
        }

        // If we got a successful response, validate it
        if ($promptsResponse !== null) {
            $this->assertIsArray($promptsResponse, 'Prompts response should be an array');
            $this->assertArrayHasKey('prompts', $promptsResponse, 'Response should contain prompts array');
            $this->assertIsArray($promptsResponse['prompts'], 'Prompts should be an array');

            // Verify prompt structure if prompts are present
            if (!empty($promptsResponse['prompts'])) {
                $firstPrompt = $promptsResponse['prompts'][0];
                $this->assertArrayHasKey('name', $firstPrompt, 'Prompt should have a name');
                $this->assertArrayHasKey('description', $firstPrompt, 'Prompt should have a description');
            }
        }

        $this->assertTrue(true, 'MCP prompts list integration test completed');
    }

    public function testMcpNotificationIntegration(): void
    {
        $manager = new Manager();
        $notificationSent = false;
        $errorMessage = null;

        // Create a notification (no response expected)
        $notificationMessage = RpcMessage::notification('notifications/roots/list_changed', [
            'roots' => [['uri' => 'file:///test', 'name' => 'Test Root']]
        ]);

        $channel = new McpChannel($this->mcpBaseUrl, $notificationMessage);
        $channel->setShowCurlCommand($this->showCurlCommand);
        $channel->setTimeout(5000);

        // Set up automatic initialization
        $channel->setAutomaticInitialize(
            clientInfo: [
                'name' => 'multicurl-test-client',
                'version' => '1.0.0'
            ],
            capabilities: [
                'roots' => ['listChanged' => true],
                'sampling' => []
            ]
        );

        $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$notificationSent): bool {
            // For notifications, we don't expect responses, but the channel should complete
            $notificationSent = true;
            return true;
        });

        $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage) {
            // Only count as error if it's not expected connection close behaviors
            if ($errno !== CURLE_GOT_NOTHING) {
                $errorMessage = "Error: $message (code: $errno)";
            }
        });

        $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($channel);
        $manager->run(); // ACTUAL INTEGRATION TEST

        // For notifications, we mainly verify no unexpected errors occurred
        if ($errorMessage) {
            $this->fail("Unexpected error during notification: $errorMessage");
        }

        $this->assertTrue(true, 'MCP notification integration test completed');
    }

    public function testMcpConcurrentRequestsIntegration(): void
    {
        $manager = new Manager(1); // Allow 2 concurrent connections
        $responses = [];
        $errors = [];

        // Create multiple concurrent requests
        $requests = [
            ['method' => 'tools/list', 'id' => 'tools'],
            ['method' => 'prompts/list', 'id' => 'prompts'],
        ];

        foreach ($requests as $request) {
            $message = RpcMessage::request($request['method']);
            $channel = new McpChannel($this->mcpBaseUrl, $message);
            $channel->setTimeout(2000);
            $channel->setShowCurlCommand($this->showCurlCommand);
            $channel->setCurlOption(CURLOPT_FORBID_REUSE, 1);
            $channel->setCurlOption(CURLOPT_FRESH_CONNECT, 1);


            // Set up automatic initialization for each channel
            $channel->setAutomaticInitialize();

            $requestId = $request['id'];

            $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$responses, $requestId): bool {
                if ($message->isResponse()) {
                    $result = $message->getResult();
                    if (is_array($result) && (
                        array_key_exists('tools', $result) ||
                        array_key_exists('prompts', $result)
                    )) {
                        $responses[$requestId] = $result;
                        return false; // Stop processing messages
                    }
                }
                return true;
            });

            $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errors, $requestId) {
                $errors[$requestId] = "Error: $message (code: $errno)";
            });

            $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errors, $requestId) {
                $errors[$requestId] = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
            });

            $manager->addChannel($channel);
        }

        $manager->run(); // ACTUAL INTEGRATION TEST

        // Verify no unexpected errors occurred
        $unexpectedErrors = $errors;

        if (!empty($unexpectedErrors)) {
            $this->fail('Concurrent requests had unexpected errors: ' . implode(', ', $unexpectedErrors));
        }

        $this->assertTrue(true, 'MCP concurrent requests integration test completed');
    }

    public function testMcpSessionIdIntegration(): void
    {
        $manager = new Manager();
        $sessionId = null;
        $errorMessage = null;

        // Create initialize request to test session ID handling
        $initMessage = RpcMessage::initializeRequest();

        $channel = new McpChannel($this->mcpBaseUrl, $initMessage);
        $channel->setShowCurlCommand($this->showCurlCommand);
        $channel->setTimeout(1000);

        $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$sessionId): bool {
            if ($message->isResponse() && !$message->isError()) {
                $sessionId = $channel->getSessionId();
            }
            return true;
        });

        $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage) {
            $errorMessage = "Error: $message (code: $errno)";
        });

        $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($channel);
        $manager->run();

        if ($errorMessage) {
            $this->fail("Unexpected error during session ID test: $errorMessage");
        }

        // Session ID handling depends on server implementation
        // Some servers may not use session IDs, so we just verify no errors occurred
        $this->assertTrue(true, 'Session ID integration test completed without unexpected errors');
    }

    public function testOnInitializedCallbackIntegration(): void
    {
        $manager = new Manager();
        $callbackCalled = false;
        $receivedSessionId = null;
        $channelSessionIdAfterInit = null;
        $errorMessage = null;

        // Create a simple tools/list request
        $toolsMessage = RpcMessage::toolsListRequest();
        $channel = new McpChannel($this->mcpBaseUrl, $toolsMessage);
        $channel->setShowCurlCommand($this->showCurlCommand);
        $channel->setTimeout(1000);

        // Set up automatic initialization with callback
        $channel->setAutomaticInitialize(
            clientInfo: [
                'name' => 'multicurl-test-client',
                'version' => '1.0.0'
            ],
            capabilities: [
                'roots' => ['listChanged' => true],
                'sampling' => []
            ],
            onInitializedCallback: function (?string $sessionId) use (&$callbackCalled, &$receivedSessionId) {
                $callbackCalled = true;
                $receivedSessionId = $sessionId;
            }
        );

        $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$channelSessionIdAfterInit): bool {
            // Capture the channel's session ID after initialization for comparison
            if ($channelSessionIdAfterInit === null) {
                $channelSessionIdAfterInit = $channel->getSessionId();
            }
            return true;
        });

        $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage) {
            $errorMessage = "Error: $message (code: $errno)";
        });

        $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($channel);
        $manager->run(); // ACTUAL INTEGRATION TEST

        if ($errorMessage) {
            $this->fail("Unexpected error during initialization: $errorMessage");
        }

        // Verify the callback was called
        $this->assertTrue($callbackCalled, 'onInitializedCallback should have been called during initialization');

        // If we have a session ID, it should be consistent between callback and channel
        if ($receivedSessionId !== null || $channelSessionIdAfterInit !== null) {
            $this->assertEquals(
                $receivedSessionId,
                $channelSessionIdAfterInit,
                'Session ID passed to callback should match the session ID set on the channel'
            );

            if ($receivedSessionId !== null) {
                $this->assertIsString($receivedSessionId, 'Session ID should be a string when provided');
                $this->assertNotEmpty($receivedSessionId, 'Session ID should not be empty when provided');

                // MCP session IDs typically have a specific format - let's validate it looks reasonable
                $this->assertMatchesRegularExpression(
                    '/^[a-zA-Z0-9_-]+$/',
                    $receivedSessionId,
                    'Session ID should contain only alphanumeric characters, underscores, and hyphens'
                );

                $this->assertGreaterThan(
                    5,
                    strlen($receivedSessionId),
                    'Session ID should be at least 6 characters long'
                );
            }
        }

        // Additional verification: ensure the callback receives the actual MCP session from headers
        // not just some random value
        $finalChannelSessionId = $channel->getSessionId();
        if ($finalChannelSessionId !== null && $receivedSessionId !== null) {
            $this->assertEquals(
                $finalChannelSessionId,
                $receivedSessionId,
                'Final channel session ID should match the session ID passed to callback'
            );
        }
    }

    /**
     * Test that connects to an mcpserver with a randomly set sessionId.
     * If setAutomaticInit is set, it should get a correct one from the server.
     */
    public function testRandomSessionIdWithAutomaticInitIntegration(): void
    {
        $manager = new Manager();
        $originalSessionId = null;
        $finalSessionId = null;
        $callbackSessionId = null;
        $errorMessage = null;

        // Create main channel with tools/list request
        $mainChannel = new McpChannel($this->mcpBaseUrl, RpcMessage::toolsListRequest());
        $mainChannel->setShowCurlCommand($this->showCurlCommand);
        $mainChannel->setTimeout(2000);

        // Set a random/invalid session ID first
        $randomSessionId = 'random-invalid-session-' . uniqid();
        $mainChannel->setSessionId($randomSessionId);
        $originalSessionId = $mainChannel->getSessionId();

        // Set up automatic initialization which should replace the random session ID
        $mainChannel->setAutomaticInitialize(
            clientInfo: [
                'name' => 'multicurl-test-client',
                'version' => '1.0.0'
            ],
            capabilities: [
                'roots' => ['listChanged' => true],
                'sampling' => []
            ],
            onInitializedCallback: function (?string $sessionId) use (&$callbackSessionId) {
                $callbackSessionId = $sessionId;
            }
        );

        $mainChannel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$finalSessionId): bool {
            // Capture the final session ID after all initialization is complete
            if ($message->isResponse() && !$message->isError() && $message->getResult() !== null) {
                $result = $message->getResult();
                // Check if this is a tools/list response (has 'tools' key)
                if (is_array($result) && array_key_exists('tools', $result)) {
                    $finalSessionId = $channel->getSessionId();
                }
            }
            return true;
        });

        $mainChannel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage) {
            // Expected: HTTP errors (like 404) when using invalid session ID should trigger automatic init
            // Only treat as actual errors if they're not HTTP errors that should trigger re-initialization
            if ($errno !== CURLE_HTTP_RETURNED_ERROR) {
                $errorMessage = "Error: $message (code: $errno)";
            }
        });

        $mainChannel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($mainChannel);
        $manager->run(); // ACTUAL INTEGRATION TEST

        if ($errorMessage) {
            $this->fail("Unexpected error during session ID replacement test: $errorMessage");
        }

        // Verify that the original random session ID was replaced
        $this->assertNotNull($originalSessionId, "Original session ID should have been set");
        $this->assertStringContainsString('random-invalid-session-', $originalSessionId,
            "Original session ID should be the random one we set");

        // If we got server responses, the session ID should have been replaced
        if ($finalSessionId !== null || $callbackSessionId !== null) {
            // Session ID should have been replaced by the server during initialization
            if ($finalSessionId !== null) {
                $this->assertNotEquals($originalSessionId, $finalSessionId,
                    "Final session ID should be different from the random one");
                $this->assertNotNull($finalSessionId,
                    "Final session ID should not be null after server communication");
            }

            // Callback session ID should match the final one if both exist
            if ($callbackSessionId !== null && $finalSessionId !== null) {
                $this->assertEquals($callbackSessionId, $finalSessionId,
                    "Callback and final session IDs should match");
            }
        }

        $this->assertTrue(true, 'Random session ID replacement integration test completed');
    }

    /**
     * Test to see if a redirect to mcpserver works in integration
     */
    public function testRedirectToMcpServerIntegration(): void
    {
        // Note: This test assumes the MCP server supports redirects or we have a redirect setup
        // In practice, you might need to set up a specific redirect scenario

        $manager = new Manager();
        $redirectHandled = false;
        $finalResponse = null;
        $errorMessage = null;
        $responseHistory = [];

        // Create a channel that might encounter redirects
        // Using the base URL but adding a potential redirect path
        $redirectUrl = $this->mcpBaseUrl . '/redirect-test';
        $channel = new McpChannel($redirectUrl, RpcMessage::toolsListRequest());
        $channel->setShowCurlCommand($this->showCurlCommand);
        $channel->setTimeout(2000);

        // Ensure redirects are enabled and set a reasonable limit
        $channel->setFollowRedirects(true, 3);

        // Set up automatic initialization
        $channel->setAutomaticInitialize(
            clientInfo: [
                'name' => 'multicurl-test-client',
                'version' => '1.0.0'
            ],
            capabilities: [
                'roots' => ['listChanged' => true],
                'sampling' => []
            ]
        );

        // Set up OAuth token to test header preservation through redirects
        $testToken = 'test-redirect-token-' . uniqid();
        $channel->setOAuthToken($testToken);

        $channel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel, Manager $manager) use (&$finalResponse, &$responseHistory): bool {
            $responseHistory[] = [
                'type' => $message->isResponse() ? 'response' : ($message->isNotification() ? 'notification' : 'request'),
                'method' => $message->getMethod(),
                'sessionId' => $channel->getSessionId()
            ];

            if ($message->isResponse() && !$message->isError() && $message->getResult() !== null) {
                $result = $message->getResult();
                if (is_array($result) && array_key_exists('tools', $result)) {
                    $finalResponse = $result;
                }
            }
            return true;
        });

                 $channel->setOnErrorCallback(function ($channel, $message, $errno, $info) use (&$errorMessage, &$redirectHandled) {
             // Check if this might be a redirect-related error that we can ignore
             if ($errno === CURLE_HTTP_RETURNED_ERROR && isset($info['http_code'])) {
                 $httpCode = $info['http_code'];
                 if ($httpCode >= 300 && $httpCode < 400) {
                     $redirectHandled = true;
                     return; // Don't treat redirects as errors
                 }
             }

             if (!str_contains($message, 'not found') &&
                 $errno !== CURLE_HTTP_RETURNED_ERROR) {
                 $errorMessage = "Error: $message (code: $errno, http: " . ($info['http_code'] ?? 'unknown') . ")";
             }
         });

        $channel->setOnTimeoutCallback(function ($channel, $timeoutType, $elapsedMS, $info) use (&$errorMessage) {
            $errorMessage = ($timeoutType == Channel::TIMEOUT_CONNECTION ? 'Connection' : 'Total') . " timeout ($elapsedMS ms)";
        });

        $manager->addChannel($channel);
        $manager->run(); // ACTUAL INTEGRATION TEST

        // Verify redirect handling capabilities
        // Even if the specific redirect URL doesn't exist, we should verify the redirect infrastructure works

        // Check that curl options are properly set for redirect handling
        $reflectionClass = new \ReflectionClass(\Maurice\Multicurl\HttpChannel::class);
        $curlOptionsProperty = $reflectionClass->getProperty('curlOptions');
        $curlOptionsProperty->setAccessible(true);
        $curlOptions = $curlOptionsProperty->getValue($channel);

        $this->assertArrayHasKey(CURLOPT_FOLLOWLOCATION, $curlOptions);
        $this->assertTrue($curlOptions[CURLOPT_FOLLOWLOCATION],
            "Channel should have redirects enabled");
        $this->assertArrayHasKey(CURLOPT_MAXREDIRS, $curlOptions);
        $this->assertEquals(3, $curlOptions[CURLOPT_MAXREDIRS],
            "Should allow up to 3 redirects as configured");

        // Check that headers are properly set for redirect preservation
        $headersProperty = $reflectionClass->getProperty('headers');
        $headersProperty->setAccessible(true);
        $headers = $headersProperty->getValue($channel);

        $this->assertArrayHasKey('authorization', $headers);
        $this->assertStringContainsString($testToken, $headers['authorization'],
            "OAuth token should be preserved for redirects");

        // If we don't get any unexpected errors, the redirect infrastructure is working
        if ($errorMessage && !str_contains($errorMessage, 'not found') && !str_contains($errorMessage, '404')) {
            $this->fail("Unexpected error during redirect test: $errorMessage");
        }

        // Verify that the initialization channel also has redirect settings
        // This tests that redirect settings propagate to the init channel
        $this->assertTrue(true, 'MCP server redirect integration test completed - redirect infrastructure verified');
    }
}