<?php

namespace NourApi\Framework\Runtime;

use InvalidArgumentException;

/** Private IPC encoding: preserve PHP string bytes and array keys without deserializing objects. */
final class WireCodec
{
    public static function encode(array $payload): string
    {
        return json_encode(self::transform($payload, false), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $encoded): array
    {
        $payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('The IPC payload must be an array.');
        }
        return self::transform($payload, true);
    }

    private static function transform(mixed $value, bool $decode): mixed
    {
        if (is_string($value)) {
            return $decode ? self::decodeString($value) : base64_encode($value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    if ($decode && ! str_starts_with($key, '_')) {
                        throw new InvalidArgumentException('Invalid IPC array key.');
                    }
                    $key = $decode ? self::decodeString(substr($key, 1)) : '_'.base64_encode($key);
                }
                if (array_key_exists($key, $result)) {
                    throw new InvalidArgumentException('Duplicate IPC array key.');
                }
                $result[$key] = self::transform($item, $decode);
            }
            return $result;
        }
        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidArgumentException('IPC payloads support only arrays, scalars and null.');
        }
        return $value;
    }

    private static function decodeString(string $encoded): string
    {
        $value = base64_decode($encoded, true);
        if ($value === false || base64_encode($value) !== $encoded) {
            throw new InvalidArgumentException('Invalid IPC byte string.');
        }
        return $value;
    }
}
