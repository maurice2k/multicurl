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
        
        // Verify _meta is included in serialization
        $this->assertArrayHasKey('_meta', $array);
        $this->assertEquals([
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ], $array['_meta']);
    }

    public function testMetaSerializationToJson(): void
    {
        $message = RpcMessage::request('test/method', ['param' => 'value']);
        $message->setMeta('sessionId', 'sess_abc123');
        $message->setMeta('userId', 'user_456');
        
        $json = $message->toJson();
        $decoded = json_decode($json, true);
        
        // Verify _meta is included in JSON
        $this->assertArrayHasKey('_meta', $decoded);
        $this->assertEquals([
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ], $decoded['_meta']);
    }

    public function testMetaDeserializationFromArray(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'test/method',
            'params' => ['param' => 'value'],
            '_meta' => [
                'sessionId' => 'sess_abc123',
                'userId' => 'user_456'
            ]
        ];
        
        $message = RpcMessage::fromArray($data);
        
        // Verify metadata was parsed correctly
        $this->assertEquals('sess_abc123', $message->getMeta('sessionId'));
        $this->assertEquals('user_456', $message->getMeta('userId'));
        $this->assertEquals([
            'sessionId' => 'sess_abc123',
            'userId' => 'user_456'
        ], $message->getMeta());
    }

    public function testMetaDeserializationFromJson(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"test/method","params":{"param":"value"},"_meta":{"sessionId":"sess_abc123","userId":"user_456"}}';
        
        $message = RpcMessage::fromJson($json);
        
        // Verify metadata was parsed correctly
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
        
        // Verify serialization doesn't include _meta
        $array = $message->toArray();
        $this->assertArrayNotHasKey('_meta', $array);
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

    public function testSetMetaWithGetMetaCopiesFullMetaBetweenMessages(): void
    {
        $source = RpcMessage::toolsListRequest();
        $source->setMeta('traceId', 't1');
        $source->setMeta('tenant', 'acme');

        $target = RpcMessage::initializeRequest();
        $target->setMeta($source->getMeta());

        $this->assertSame('t1', $target->getMeta('traceId'));
        $this->assertSame('acme', $target->getMeta('tenant'));
    }
} 