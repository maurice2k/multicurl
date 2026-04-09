<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use InvalidArgumentException;
use Maurice\Multicurl\Mcp\RpcMessage;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests the public behavior of RpcMessage.
 */
class RpcMessageTest extends TestCase
{
    public function testRequestCreatesExpectedMessage(): void
    {
        $message = RpcMessage::request('tools/list', ['foo' => 'bar'], 'req-1');

        $this->assertTrue($message->isRequest());
        $this->assertSame('tools/list', $message->getMethod());
        $this->assertSame(['foo' => 'bar'], $message->getParams());
        $this->assertSame('req-1', $message->getId());
    }

    public function testNotificationKeepsRequestIdInParamsAndOmitsTopLevelId(): void
    {
        $message = RpcMessage::notification('notifications/cancelled', ['requestId' => 1]);

        $payload = $message->toArray();

        $this->assertTrue($message->isNotification());
        $this->assertSame(['requestId' => 1], $payload['params']);
        $this->assertArrayNotHasKey('id', $payload);
    }

    public function testResponseCreatesExpectedMessage(): void
    {
        $message = RpcMessage::response(['capabilities' => []], '1');

        $this->assertTrue($message->isResponse());
        $this->assertSame(['capabilities' => []], $message->getResult());
        $this->assertSame('1', $message->getId());
    }

    public function testErrorCreatesExpectedMessage(): void
    {
        $message = RpcMessage::error(-32603, 'Internal error', ['detail' => 'x'], '9');

        $this->assertTrue($message->isError());
        $this->assertSame(-32603, $message->getErrorCode());
        $this->assertSame('Internal error', $message->getErrorMessage());
        $this->assertSame(['detail' => 'x'], $message->getError()['data']);
        $this->assertSame('9', $message->getId());
    }

    public function testToolsListRequestUsesEmptyObjectParams(): void
    {
        $message = RpcMessage::toolsListRequest();

        $this->assertTrue($message->isRequest());
        $this->assertSame('tools/list', $message->getMethod());
        $this->assertInstanceOf(stdClass::class, $message->getParams());
    }

    public function testToolsCallRequestBuildsExpectedParams(): void
    {
        $message = RpcMessage::toolsCallRequest('my_tool', ['a' => 1], ['type' => 'object']);

        $this->assertSame('tools/call', $message->getMethod());
        $this->assertSame(
            [
                'name' => 'my_tool',
                'arguments' => ['a' => 1],
                'outputSchema' => ['type' => 'object'],
            ],
            $message->getParams()
        );
    }

    public function testToolsCallRequestOmitsOutputSchemaWhenNotProvided(): void
    {
        $message = RpcMessage::toolsCallRequest('my_tool', ['a' => 1]);

        $this->assertArrayNotHasKey('outputSchema', $message->getParams());
    }

    public function testInitializeRequestUsesDefaultClientInfoAndEmptyCapabilitiesObject(): void
    {
        $message = RpcMessage::initializeRequest();
        $params = $message->getParams();

        $this->assertSame('initialize', $message->getMethod());
        $this->assertSame('2025-06-18', $params['protocolVersion']);
        $this->assertSame(
            [
                'name' => 'maurice2k/multicurl MCP Client',
                'version' => '1.0.0',
            ],
            $params['clientInfo']
        );
        $this->assertInstanceOf(stdClass::class, $params['capabilities']);
    }

    public function testInitializeRequestKeepsProvidedClientInfoAndCapabilities(): void
    {
        $clientInfo = [
            'name' => 'Test Client',
            'version' => '1.2.3',
        ];
        $capabilities = [
            'tools' => ['listChanged' => true],
            'roots' => ['listChanged' => false],
        ];

        $message = RpcMessage::initializeRequest('2025-06-18', $clientInfo, $capabilities);

        $this->assertSame($clientInfo, $message->getParams()['clientInfo']);
        $this->assertSame($capabilities, $message->getParams()['capabilities']);
    }

    public function testFromJsonRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        RpcMessage::fromJson('{');
    }

    public function testFromJsonParsesRequestMessage(): void
    {
        $message = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","method":"tools/list","id":"1","params":{"foo":"bar"}}'
        );

        $this->assertTrue($message->isRequest());
        $this->assertSame('tools/list', $message->getMethod());
        $this->assertSame('1', $message->getId());
        $this->assertSame(['foo' => 'bar'], $message->getParams());
    }

    public function testFromArrayRejectsMissingJsonRpcVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC version');

        RpcMessage::fromArray([]);
    }

    public function testFromArrayRejectsWrongJsonRpcVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC version');

        RpcMessage::fromArray([
            'jsonrpc' => '1.0',
            'id' => 1,
            'result' => null,
        ]);
    }

    public function testFromArrayParsesNotificationMessage(): void
    {
        $message = RpcMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => ['status' => 'ready'],
        ]);

        $this->assertTrue($message->isNotification());
        $this->assertSame('notifications/initialized', $message->getMethod());
        $this->assertNull($message->getId());
        $this->assertSame(['status' => 'ready'], $message->getParams());
    }

    public function testFromArrayParsesResponseMessage(): void
    {
        $message = RpcMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['x' => 1],
        ]);

        $this->assertTrue($message->isResponse());
        $this->assertSame(2, $message->getId());
        $this->assertSame(['x' => 1], $message->getResult());
    }

    public function testFromArrayParsesErrorMessage(): void
    {
        $message = RpcMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => [
                'code' => -32600,
                'message' => 'Invalid Request',
            ],
        ]);

        $this->assertTrue($message->isError());
        $this->assertSame(3, $message->getId());
        $this->assertSame(-32600, $message->getErrorCode());
        $this->assertSame('Invalid Request', $message->getErrorMessage());
    }
}
