<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Mcp\RpcMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RpcMessage class
 */
class RpcMessageTest extends TestCase
{
    public function testSetMetaSingleField(): void
    {
        $message = RpcMessage::request('test/method');
        
        // Test setting a single metadata field
        $message->setMeta('sessionId', 'sess_abc123');
        
        $this->assertEquals('sess_abc123', $message->getMeta('sessionId'));
    }

    public function testSetMetaMultipleFields(): void
    {
        $message = RpcMessage::request('test/method');
        
        // Test setting multiple metadata fields
        $message->setMeta('sessionId', 'sess_abc123');
        $message->setMeta('userId', 'user_456');
        $message->setMeta('requestId', 'req_789');
        
        $this->assertEquals('sess_abc123', $message->getMeta('sessionId'));
        $this->assertEquals('user_456', $message->getMeta('userId'));
        $this->assertEquals('req_789', $message->getMeta('requestId'));
    }

    public function testGetMetaSpecificField(): void
    {
        $message = RpcMessage::request('test/method');
        
        $message->setMeta('sessionId', 'sess_abc123');
        $message->setMeta('userId', 'user_456');
        
        // Test getting specific fields
        $this->assertEquals('sess_abc123', $message->getMeta('sessionId'));
        $this->assertEquals('user_456', $message->getMeta('userId'));
    }

    public function testGetMetaNonexistentField(): void
    {
        $message = RpcMessage::request('test/method');
        
        $message->setMeta('sessionId', 'sess_abc123');
        
        // Test getting nonexistent field returns null
        $this->assertNull($message->getMeta('nonexistent'));
    }

    public function testGetMetaFullStructure(): void
    {
        $message = RpcMessage::request('test/method');
        
        // Test getting full metadata when empty
        $this->assertNull($message->getMeta());
        
        // Add some metadata
        $message->setMeta('sessionId', 'sess_abc123');
        $message->setMeta('userId', 'user_456');
        
        // Test getting full metadata structure
        $expected = [
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ];
        $this->assertEquals($expected, $message->getMeta());
    }

    public function testSetMetaOverwriteField(): void
    {
        $message = RpcMessage::request('test/method');
        
        // Set initial value
        $message->setMeta('sessionId', 'sess_abc123');
        $this->assertEquals('sess_abc123', $message->getMeta('sessionId'));
        
        // Overwrite with new value
        $message->setMeta('sessionId', 'sess_xyz789');
        $this->assertEquals('sess_xyz789', $message->getMeta('sessionId'));
    }

    public function testSetMetaVariousDataTypes(): void
    {
        $message = RpcMessage::request('test/method');
        
        // Test various data types
        $message->setMeta('stringField', 'test_string');
        $message->setMeta('intField', 42);
        $message->setMeta('floatField', 3.14);
        $message->setMeta('boolField', true);
        $message->setMeta('arrayField', ['key' => 'value']);
        $message->setMeta('nullField', null);
        
        $this->assertEquals('test_string', $message->getMeta('stringField'));
        $this->assertEquals(42, $message->getMeta('intField'));
        $this->assertEquals(3.14, $message->getMeta('floatField'));
        $this->assertTrue($message->getMeta('boolField'));
        $this->assertEquals(['key' => 'value'], $message->getMeta('arrayField'));
        $this->assertNull($message->getMeta('nullField'));
    }

    public function testMetaSerializationToArray(): void
    {
        $message = RpcMessage::request('test/method', ['param' => 'value']);
        $message->setMeta('sessionId', 'sess_abc123');
        $message->setMeta('userId', 'user_456');

        $array = $message->toArray();

        // _meta must be inside params, not at the root level
        $this->assertArrayNotHasKey('_meta', $array);
        $this->assertArrayHasKey('_meta', $array['params']);
        $this->assertEquals([
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ], $array['params']['_meta']);
    }

    public function testMetaSerializationToJson(): void
    {
        $message = RpcMessage::request('test/method', ['param' => 'value']);
        $message->setMeta('sessionId', 'sess_abc123');
        $message->setMeta('userId', 'user_456');

        $json = $message->toJson();
        $decoded = json_decode($json, true);

        // _meta must be inside params, not at the root level
        $this->assertArrayNotHasKey('_meta', $decoded);
        $this->assertArrayHasKey('_meta', $decoded['params']);
        $this->assertEquals([
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ], $decoded['params']['_meta']);
    }

    public function testMetaDeserializationFromArray(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'test/method',
            'params' => [
                'param' => 'value',
                '_meta' => [
                    'sessionId' => 'sess_abc123',
                    'userId' => 'user_456'
                ]
            ]
        ];

        $message = RpcMessage::fromArray($data);

        // Verify metadata was extracted from params
        $this->assertEquals('sess_abc123', $message->getMeta('sessionId'));
        $this->assertEquals('user_456', $message->getMeta('userId'));
        $this->assertEquals([
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ], $message->getMeta());

        // params still contains _meta as-is from the wire
        $this->assertEquals('value', $message->getParams()['param']);
    }

    public function testMetaDeserializationFromJson(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"test/method","params":{"param":"value","_meta":{"sessionId":"sess_abc123","userId":"user_456"}}}';

        $message = RpcMessage::fromJson($json);

        // Verify metadata was extracted from params
        $this->assertEquals('sess_abc123', $message->getMeta('sessionId'));
        $this->assertEquals('user_456', $message->getMeta('userId'));
        $this->assertEquals([
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ], $message->getMeta());
    }

    public function testMetaRoundTripSerialization(): void
    {
        // Create message with metadata
        $original = RpcMessage::toolsCallRequest('search_documents', ['query' => 'test']);
        $original->setMeta('sessionId', 'sess_abc123');
        $original->setMeta('userId', 'user_456');
        $original->setMeta('requestTime', 1640995200);
        
        // Serialize to JSON and back
        $json = $original->toJson();
        $restored = RpcMessage::fromJson($json);
        
        // Verify all metadata is preserved
        $this->assertEquals($original->getMeta('sessionId'), $restored->getMeta('sessionId'));
        $this->assertEquals($original->getMeta('userId'), $restored->getMeta('userId'));
        $this->assertEquals($original->getMeta('requestTime'), $restored->getMeta('requestTime'));
        $this->assertEquals($original->getMeta(), $restored->getMeta());
    }

    public function testMetaWithoutMetadata(): void
    {
        $message = RpcMessage::request('test/method');

        // Test message without any metadata
        $this->assertNull($message->getMeta());
        $this->assertNull($message->getMeta('anyField'));

        // Verify serialization doesn't include _meta at root or in params
        $array = $message->toArray();
        $this->assertArrayNotHasKey('_meta', $array);
        if (is_array($array['params'] ?? null)) {
            $this->assertArrayNotHasKey('_meta', $array['params']);
        }
    }

    public function testMetaMergesWithExistingParamsMeta(): void
    {
        // Params already contain a _meta with progressToken
        $params = [
            '_meta' => ['progressToken' => 'tok-42'],
            'name' => 'my-tool',
        ];
        $message = RpcMessage::request('tools/call', $params);

        // setMeta adds extra fields via the public API
        $message->setMeta('sessionId', 'sess_abc');

        $array = $message->toArray();

        // Both the original progressToken and the added sessionId must be present
        $this->assertEquals('tok-42', $array['params']['_meta']['progressToken']);
        $this->assertEquals('sess_abc', $array['params']['_meta']['sessionId']);
    }

    public function testMetaSetViaApiOverridesExistingParamsMetaKey(): void
    {
        // Params already have a _meta key that will also be set via setMeta
        $params = [
            '_meta' => ['progressToken' => 'old-token'],
            'name' => 'my-tool',
        ];
        $message = RpcMessage::request('tools/call', $params);

        // Override the same key via the public API — setMeta wins
        $message->setMeta('progressToken', 'new-token');

        $array = $message->toArray();
        $this->assertEquals('new-token', $array['params']['_meta']['progressToken']);
    }

    public function testMetaMergeRoundTrip(): void
    {
        // Build a request whose params already contain _meta.progressToken
        $params = [
            '_meta' => ['progressToken' => 'tok-99'],
            'name' => 'search',
        ];
        $message = RpcMessage::request('tools/call', $params);
        $message->setMeta('traceId', 'trace-1');

        // Serialize and parse back
        $restored = RpcMessage::fromJson($message->toJson());

        $this->assertEquals('tok-99', $restored->getMeta('progressToken'));
        $this->assertEquals('trace-1', $restored->getMeta('traceId'));
    }

    public function testMetaMergesWithExistingResultMeta(): void
    {
        $result = [
            '_meta' => ['cursor' => 'page2'],
            'tools' => [],
        ];
        $message = RpcMessage::response($result, 1);

        $message->setMeta('extra', 'value');

        $array = $message->toArray();

        $this->assertEquals('page2', $array['result']['_meta']['cursor']);
        $this->assertEquals('value', $array['result']['_meta']['extra']);
    }

    public function testMetaInResponseResult(): void
    {
        // Verify _meta is nested inside result for responses
        $message = RpcMessage::response(['tools' => []], 1);
        $message->setMeta('serverTime', 1640995200);

        $array = $message->toArray();

        $this->assertArrayNotHasKey('_meta', $array);
        $this->assertArrayHasKey('_meta', $array['result']);
        $this->assertEquals(['serverTime' => 1640995200], $array['result']['_meta']);
    }

    public function testMetaDeserializationFromResponse(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'tools' => [],
                '_meta' => ['serverTime' => 1640995200]
            ]
        ];

        $message = RpcMessage::fromArray($data);

        $this->assertEquals(1640995200, $message->getMeta('serverTime'));
        $this->assertArrayHasKey('tools', $message->getResult());
    }

    public function testMetaPreservesStdClassProperties(): void
    {
        // P1: stdClass params must not lose existing properties when _meta is added
        $message = RpcMessage::request('x', (object)['foo' => 'bar']);
        $message->setMeta('traceId', 'trace-1');

        $array = $message->toArray();

        $this->assertEquals('bar', $array['params']['foo']);
        $this->assertEquals('trace-1', $array['params']['_meta']['traceId']);
    }

    public function testMetaPreservesStdClassResultProperties(): void
    {
        $message = RpcMessage::response((object)['tools' => []], 1);
        $message->setMeta('cursor', 'page2');

        $array = $message->toArray();

        $this->assertEquals([], $array['result']['tools']);
        $this->assertEquals('page2', $array['result']['_meta']['cursor']);
    }

    public function testFromArrayReadsRootLevelMetaForBackwardCompat(): void
    {
        // P2: old wire format with _meta at root level must still be understood
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'test/method',
            'params' => ['param' => 'value'],
            '_meta' => ['sessionId' => 'sess_old']
        ];

        $message = RpcMessage::fromArray($data);

        $this->assertEquals('sess_old', $message->getMeta('sessionId'));
    }

    public function testFromArrayPrefersNestedMetaOverRootLevel(): void
    {
        // When both root-level and nested _meta exist, nested wins
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'test/method',
            'params' => [
                'param' => 'value',
                '_meta' => ['source' => 'nested']
            ],
            '_meta' => ['source' => 'root']
        ];

        $message = RpcMessage::fromArray($data);

        $this->assertEquals('nested', $message->getMeta('source'));
    }

    public function testSetMetaBulkArray(): void
    {
        $message = RpcMessage::request('test/method');

        $result = $message->setMeta(['foo' => 'bar', 'baz' => 42]);

        $this->assertSame($message, $result); // returns static
        $this->assertEquals(['foo' => 'bar', 'baz' => 42], $message->getMeta());
    }

    public function testSetMetaBulkArrayReplacesExisting(): void
    {
        $message = RpcMessage::request('test/method');
        $message->setMeta('old', 'value');

        $message->setMeta(['new' => 'data']);

        // old key must be gone — array replaces, does not merge
        $this->assertNull($message->getMeta('old'));
        $this->assertEquals('data', $message->getMeta('new'));
    }

    public function testSetMetaEmptyArrayClearsMeta(): void
    {
        $message = RpcMessage::request('test/method');
        $message->setMeta('foo', 'bar');

        $message->setMeta([]);

        $this->assertNull($message->getMeta());
    }

    public function testSetMetaNullClearsMeta(): void
    {
        $message = RpcMessage::request('test/method');
        $message->setMeta('foo', 'bar');

        $message->setMeta(null);

        $this->assertNull($message->getMeta());
    }

    public function testSetMetaNoArgsClearsMeta(): void
    {
        $message = RpcMessage::request('test/method');
        $message->setMeta('foo', 'bar');

        $message->setMeta();

        $this->assertNull($message->getMeta());
    }

    public function testSetMetaChaining(): void
    {
        $message = RpcMessage::request('test/method');

        $result = $message->setMeta('a', 1)->setMeta('b', 2);

        $this->assertSame($message, $result);
        $this->assertEquals(1, $message->getMeta('a'));
        $this->assertEquals(2, $message->getMeta('b'));
    }
}