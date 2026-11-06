<?php

use NourApi\Framework\Runtime\WireCodec;
use PHPUnit\Framework\TestCase;

class WireCodecTest extends TestCase
{
    public function testRoundTripPreservesArbitraryBytesAndTypes(): void
    {
        $payload = [
            "\xff\x00" => ["\xff\xfe\x00", '0', '', null, true, false, 0, -1, 1.0, 1.25],
            '' => [], 45 => [4 => 'integer keys', -2 => '_reserved-looking string'],
            '_YWJj' => ['arbitrary' => implode('', array_map('chr', range(0, 255)))],
        ];
        self::assertSame($payload, WireCodec::decode(WireCodec::encode($payload)));
    }

    public function testObjectEncodingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WireCodec::encode(['object' => new stdClass]);
    }

    public function testNonArrayEnvelopeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WireCodec::decode('null');
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(JsonException::class);
        WireCodec::decode('{invalid');
    }

    public function testUnencodedArrayKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WireCodec::decode('{"raw-key":""}');
    }

    public function testInvalidByteStringIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WireCodec::decode('["not base64!"]');
    }

    public function testAmbiguousKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WireCodec::decode('{"0":"", "_MA==":""}');
    }
}
