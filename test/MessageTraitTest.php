<?php
namespace PhlyTest\Http;

use Phly\Http\Request;
use Phly\Http\Stream;
use PHPUnit_Framework_TestCase as TestCase;

class MessageTraitTest extends TestCase
{
    public function setUp()
    {
        $this->stream  = new Stream('php://memory', 'wb+');
        $this->message = new Request($this->stream);
    }

    public function testProtocolHasAcceptableDefault()
    {
        $this->assertEquals('1.1', $this->message->getProtocolVersion());
    }

    public function testProtocolIsMutable()
    {
        $this->message->setProtocolVersion('1.0');
        $this->assertEquals('1.0', $this->message->getProtocolVersion());
    }

    public function testUsesStreamProvidedInConstructorAsBody()
    {
        $this->assertSame($this->stream, $this->message->getBody());
    }

    public function testBodyIsMutable()
    {
        $stream  = new Stream('php://memory', 'wb+');
        $this->message->setBody($stream);
        $this->assertSame($stream, $this->message->getBody());
    }

    public function testGetHeaderLinesReturnsHeaderValueAsArray()
    {
        $this->message->setHeader('X-Foo', ['Foo', 'Bar']);
        $this->assertEquals(['Foo', 'Bar'], $this->message->getHeaderLines('X-Foo'));
    }

    public function testGetHeaderReturnsHeaderValueAsCommaConcatenatedString()
    {
        $this->message->setHeader('X-Foo', ['Foo', 'Bar']);
        $this->assertEquals('Foo,Bar', $this->message->getHeader('X-Foo'));
    }

    public function testHasHeaderReturnsFalseIfHeaderIsNotPresent()
    {
        $this->assertFalse($this->message->hasHeader('X-Foo'));
    }

    public function testHasHeaderReturnsTrueIfHeaderIsPresent()
    {
        $this->message->setHeader('X-Foo', 'Foo');
        $this->assertTrue($this->message->hasHeader('X-Foo'));
    }

    public function testAddHeaderAppendsToExistingHeader()
    {
        $this->message->setHeader('X-Foo', 'Foo');
        $this->message->addHeader('X-Foo', 'Bar');
        $this->assertEquals('Foo,Bar', $this->message->getHeader('X-Foo'));
    }

    public function testCanRemoveHeaders()
    {
        $this->message->setHeader('X-Foo', 'Foo');
        $this->assertTrue($this->message->hasHeader('x-foo'));
        $this->message->removeHeader('x-foo');
        $this->assertFalse($this->message->hasHeader('X-Foo'));
    }

    public function invalidGeneralHeaderValues()
    {
        return [
            'null'   => [null],
            'true'   => [true],
            'false'  => [false],
            'int'    => [1],
            'float'  => [1.1],
            'array'  => [[ 'foo' => [ 'bar' ] ]],
            'object' => [(object) [ 'foo' => 'bar' ]],
        ];
    }

    /**
     * @dataProvider invalidGeneralHeaderValues
     */
    public function testSetHeaderRaisesExceptionForInvalidNestedHeaderValue($value)
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid header value');
        $this->message->setHeader('X-Foo', [ $value ]);
    }

    public function invalidHeaderValues()
    {
        return [
            'null'   => [null],
            'true'   => [true],
            'false'  => [false],
            'int'    => [1],
            'float'  => [1.1],
            'object' => [(object) [ 'foo' => 'bar' ]],
        ];
    }

    /**
     * @dataProvider invalidHeaderValues
     */
    public function testSetHeaderRaisesExceptionForInvalidValueType($value)
    {
        $this->setExpectedException('InvalidArgumentException', 'Invalid header value');
        $this->message->setHeader('X-Foo', $value);
    }

    /**
     * @dataProvider invalidGeneralHeaderValues
     */
    public function testAddHeaderRaisesExceptionForNonStringNonArrayValue($value)
    {
        $this->setExpectedException('InvalidArgumentException', 'must be a string');
        $this->message->addHeader('X-Foo', $value);
    }

    public function testRemoveHeaderDoesNothingIfHeaderDoesNotExist()
    {
        $this->assertFalse($this->message->hasHeader('X-Foo'));
        $this->message->removeHeader('X-Foo');
        $this->assertFalse($this->message->hasHeader('X-Foo'));
    }
}
