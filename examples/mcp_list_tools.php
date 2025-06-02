<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Manager;
use Maurice\Multicurl\McpChannel;
use Maurice\Multicurl\Mcp\RpcMessage;

$mcpUrl = 'https://remote.mcpservers.org/fetch/mcp';

$manager = new Manager();

$listToolsChannel = new McpChannel($mcpUrl, RpcMessage::toolsListRequest());
//$listToolsChannel->setBearerAuth('xxxxxxxxxxxx');
//$listToolsChannel->setShowCurlCommand(true);
//$listToolsChannel->setVerbose(true);

// Automatically initialize MCP channel (two more requests will be sent)
$listToolsChannel->setAutomaticInitialize();

// Set up MCP message handling for the tools/list response
$listToolsChannel->setOnMcpMessageCallback(function (RpcMessage $message, McpChannel $channel) use ($manager) {
    echo "Received MCP Message (ID: {$message->getId()}, Type: {$message->getType()})\n";

    if ($message->isResponse() && $message->getId() === $channel->getRpcMessage()->getId()) { // Response to tools/list request
        if ($message->getResult() && isset($message->getResult()['tools'])) {
            echo "Available Tools:\n";
            foreach ($message->getResult()['tools'] as $tool) {
                // Build function-like definition with parameters
                $functionDef = $tool['name'];
                if (isset($tool['inputSchema']) && isset($tool['inputSchema']['properties'])) {
                    $params = [];
                    foreach ($tool['inputSchema']['properties'] as $paramName => $paramProps) {
                        $type = $paramProps['type'] ?? 'mixed';
                        $required = in_array($paramName, $tool['inputSchema']['required'] ?? []) ? '' : '?';
                        $params[] = "{$type}{$required} \${$paramName}";
                    }
                    $functionDef .= "(" . implode(', ', $params) . ")";
                } else {
                    $functionDef .= "()";
                }
                
                echo "- Name: {$functionDef}\n";
                echo "  Description: ".($tool['description'] ?? 'No description available')."\n";
                
                // Optionally print inputSchema
                // echo "  Input Schema: " . json_encode($tool['inputSchema']) . "\n";
            }
        } elseif ($message->getError()) {
            echo "Error listing tools: {$message->getError()['message']} (Code: {$message->getError()['code']})\n";
        } else {
            echo "Unexpected response format for tools/list\n";
        }

        return false; // abort connection
    } else if ($message->isError()) {
        echo "Error during tools listing: {$message->getError()['message']} (Code: {$message->getError()['code']})\n";
    }
});

// Set up error handling for the main channel
$listToolsChannel->setOnErrorCallback(function(Channel $channel, string $error, int $code, array $info, Manager $manager) {
    echo "Error: {$error} (Code: {$code})\n";
    echo "HTTP status: " . $info['http_code'] . "\n";
});

// Add the channel to the manager
$manager->addChannel($listToolsChannel);

// Execute the requests
$manager->run();
