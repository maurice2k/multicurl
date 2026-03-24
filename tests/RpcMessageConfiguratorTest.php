<?php
declare(strict_types = 1);

namespace Maurice\Multicurl\Tests;

use Closure;
use Maurice\Multicurl\Manager;
use Maurice\Multicurl\McpChannel;
use Maurice\Multicurl\Mcp\RpcMessage;
use Maurice\Multicurl\Mcp\RpcMessageConfiguratorInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RpcMessageConfiguratorTest extends TestCase
{
    public function testChannelWithoutConfiguratorLeavesMessageUnchanged(): void
    {
        $channel = new McpChannel('file:///dev/null', RpcMessage::toolsListRequest());
        $decoded = json_decode($channel->getRpcMessage()->toJson(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('_meta', $decoded);
    }

    public function testConfiguratorSetsMetaViaAnonymousClass(): void
    {
        $configurator = new class implements RpcMessageConfiguratorInterface {
            public function configure(RpcMessage $message): void
            {
                $message->setMeta('k', 'v');
            }
        };

        $channel = new McpChannel('file:///dev/null', RpcMessage::toolsListRequest(), $configurator);
        $decoded = json_decode($channel->getRpcMessage()->toJson(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('v', $decoded['_meta']['k'] ?? null);
    }

    public function testConfiguratorInvokedForConstructorOutboundMessage(): void
    {
        $configurator = $this->createTrackingConfigurator();
        $channel = new McpChannel('file:///dev/null', RpcMessage::toolsListRequest(), $configurator);

        $this->assertSame(['tools/list'], $configurator->methods);
        $decoded = json_decode($channel->getRpcMessage()->toJson(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('tools/list', $decoded['_meta']['lastMethod'] ?? null);
    }

    public function testConfiguratorInvokedForSetRpcMessage(): void
    {
        $configurator = $this->createTrackingConfigurator();
        $channel = new McpChannel('file:///dev/null', null, $configurator);
        $configurator->methods = [];

        $channel->setRpcMessage(RpcMessage::promptsListRequest());

        $this->assertSame(['prompts/list'], $configurator->methods);
    }

    public function testConfiguratorInvokedForAutoInitializeInitializeAndNotification(): void
    {
        $configurator = $this->createTrackingConfigurator();
        $main = new McpChannel('file:///dev/null', RpcMessage::toolsListRequest(), $configurator);

        $this->assertContains('tools/list', $configurator->methods);

        $main->setAutomaticInitialize();
        $init = $main->popBeforeChannel();
        $this->assertNotNull($init);
        assert($init instanceof McpChannel);

        $this->assertContains('initialize', $configurator->methods);

        $initRpc = $init->getRpcMessage();
        $decodedInit = json_decode($initRpc->toJson(), true);
        $this->assertIsArray($decodedInit);
        $this->assertSame('initialize', $decodedInit['_meta']['lastMethod'] ?? null);

        $reflection = new ReflectionClass(McpChannel::class);
        $property = $reflection->getProperty('onMcpMessageCb');
        $property->setAccessible(true);
        $callback = $property->getValue($init);
        $this->assertInstanceOf(Closure::class, $callback);

        $response = RpcMessage::response(
            [
                'protocolVersion' => '2025-06-18',
                'serverInfo' => ['name' => 'test', 'version' => '1.0.0'],
            ],
            $initRpc->getId()
        );

        $callback($response, $init, new Manager(0));

        $next = $init->popNextChannel();
        $this->assertNotNull($next);
        assert($next instanceof McpChannel);
        $this->assertSame('notifications/initialized', $next->getRpcMessage()->getMethod());

        $this->assertContains('notifications/initialized', $configurator->methods);

        $decodedNotify = json_decode($next->getRpcMessage()->toJson(), true);
        $this->assertIsArray($decodedNotify);
        $this->assertSame('notifications/initialized', $decodedNotify['_meta']['lastMethod'] ?? null);
    }

    private function createTrackingConfigurator(): RpcMessageConfiguratorInterface
    {
        return new class implements RpcMessageConfiguratorInterface {
            /** @var list<string|null> */
            public array $methods = [];

            public function configure(RpcMessage $message): void
            {
                $this->methods[] = $message->getMethod();
                $message->setMeta('lastMethod', $message->getMethod());
            }
        };
    }
}
