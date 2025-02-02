<?php

declare(strict_types=1);

namespace Merkeleon\PhpCryptocurrencyAddressValidation\Utils;

use Merkeleon\PhpCryptocurrencyAddressValidation\Exception\Base32Exception;
use Merkeleon\PhpCryptocurrencyAddressValidation\Exception\InvalidChecksumException;
use function array_key_exists;
use function array_slice;
use function array_values;
use function gmp_and;
use function gmp_cmp;
use function gmp_div;
use function gmp_init;
use function gmp_mul;
use function gmp_pow;
use function gmp_strval;
use function gmp_xor;
use function ord;
use function pack;
use function strlen;
use function strtolower;
use function unpack;

class Base32Decoder
{
    /**
     * @var string
     */
    private const SEPARATOR = ':';

    /**
     * @var string
     */
    protected static string $maskHex = '07ffffffff';

    /**
     * @var int
     */
    protected static int $checksumLen = 8;

    /**
     * @var string
     */
    protected static string $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /**
     * @var array
     */
    protected static array $charsetKey = [
        -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
        -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
        -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
        15, -1, 10, 17, 21, 20, 26, 30, 7, 5, -1, -1, -1, -1, -1, -1,
        -1, 29, -1, 24, 13, 25, 9, 8, 23, -1, 18, 22, 31, 27, 19, -1,
        1, 0, 3, 16, 11, 28, 12, 14, 6, 4, 2, -1, -1, -1, -1, -1,
        -1, 29, -1, 24, 13, 25, 9, 8, 23, -1, 18, 22, 31, 27, 19, -1,
        1, 0, 3, 16, 11, 28, 12, 14, 6, 4, 2, -1, -1, -1, -1, -1
    ];

    /**
     * @var array
     */
    protected static array $generator = [0x98f2bc8e61, 0x79b76d99e2, 0xf33e5fb3c4, 0xae2eabe2a8, 0x1e4f43e470];

    /**
     * {@inheritDoc}
     * @see GmpMathInterface::bitwiseAnd()
     */
    protected static function bitwiseAnd($first, $other)
    {
        return gmp_and($first, $other);
    }

    /**
     * {@inheritDoc}
     * @see GmpMathInterface::rightShift()
     */
    protected static function rightShift($number, $positions)
    {
        // Shift 1 right = div / 2
        return gmp_div($number, gmp_pow(gmp_init(2, 10), $positions));
    }

    /**
     * {@inheritDoc}
     * @see GmpMathInterface::bitwiseXor()
     */
    protected static function bitwiseXor($first, $other)
    {
        return gmp_xor($first, $other);
    }

    /**
     * {@inheritDoc}
     * @see GmpMathInterface::leftShift()
     */
    protected static function leftShift($number, $positions)
    {
        // Shift 1 left = mul by 2
        return gmp_mul($number, gmp_pow(2, $positions));
    }

    /**
     * @param  \GMP  $prev
     * @return resource
     */
    protected static function polyModStep($prev)
    {
        $mask = gmp_init(self::$maskHex, 16);
        $one = gmp_init(1);

        $b = self::rightShift($prev, 35);
        $v = self::leftShift(self::bitwiseAnd($prev, $mask), 5);

        for ($j = 0; $j < 5; $j++) {
            $value = 0;
            if (gmp_cmp(self::bitwiseAnd(self::rightShift($b, $j), $one), 0) > 0) {
                $value = self::$generator[$j];
            }

            $v = self::bitwiseXor($v, gmp_init((string) $value, 10));
        }

        return $v;
    }

    /**
     * @param string $prefix
     *
     * @return resource
     */
    public static function prefixChk(string $prefix)
    {
        $chk = gmp_init(1);
        $length = strlen($prefix);
        for ($i = 0; $i < $length; $i++) {
            $char = ord($prefix[$i]) & 0x1f;
            $chk = self::bitwiseXor(self::polyModStep($chk), gmp_init($char, 10));
        }
        return self::polyModStep($chk);
    }

    /**
     * @param string $string - base32 string
     *
     * @return array<int, array<int, mixed>|string> - array<prefix, array<5 bit int>>
     * @throws Base32Exception
     * @throws InvalidChecksumException
     */
    public static function decode(string $string, bool $hasPrefix = true): array
    {
        $stringLen = strlen($string);
        if ($stringLen < 8) {
            throw new Base32Exception("Address too short");
        }

        if ($stringLen > 90) {
            throw new Base32Exception("Address too long");
        }

        $chars = array_values(unpack("C*", $string));

        $haveUpper = $haveLower = false;
        $idxSeparator = -1;
        $separatorChar = ord(self::SEPARATOR);

        for ($i = 0; $i < $stringLen; $i++) {
            $x = $chars[$i];
            if ($x < 33 || $x > 126) {
                throw new Base32Exception("Out of range character in base32 string");
            }

            if ($x >= 0x61 && $x <= 0x7a) {
                $haveLower = true;
            }

            if ($x >= 0x41 && $x <= 0x5a) {
                $haveUpper = true;
                $x = $chars[$i] = $x + 0x20;
            }

            if ($x === $separatorChar) {
                $idxSeparator = $i;
            }
        }

        if ($haveUpper && $haveLower) {
            throw new Base32Exception("Data contains mixture of higher/lower case characters");
        }

        if ($hasPrefix && $idxSeparator === -1) {
            throw new Base32Exception("Missing separator character");
        }
        if ($hasPrefix && $idxSeparator === 0) {
            throw new Base32Exception("Missing prefix");
        }

        if (($idxSeparator + 7) > $stringLen) {
            throw new Base32Exception("Invalid location for separator character");
        }

        $prefix = "";

        foreach (array_slice($chars, 0, $idxSeparator) as $byte) {
            $prefix .= pack("C*", $byte);
        }

        $chk = self::prefixChk($prefix);

        $words = [];
        for ($i = $idxSeparator + 1; $i < $stringLen; $i++) {
            $char = $chars[$i];
            if (!array_key_exists($char, self::$charsetKey)) {
                throw new Base32Exception("Unknown character in address");
            }
            $word = self::$charsetKey[$char];
            $chk = self::bitwiseXor(self::polyModStep($chk), gmp_init($word));
            $words[] = $word;
        }

        if ($hasPrefix && gmp_cmp($chk, gmp_init(1)) !== 0) {
            throw new InvalidChecksumException();
        }

        return [
            $prefix,
            array_slice($words, 0, -self::$checksumLen)
        ];
    }

    /**
     * Convert $bytes, an array of 8 bit numbers, to
     * words, an array of 5 bit numbers.
     *
     * @param  int  $numBytes
     * @param  int[]  $bytes
     * @return int[]
     * @throws Base32Exception
     */
    public static function toWords($numBytes, array $bytes): array
    {
        return self::convertBits($bytes, $numBytes, 8, 5, true);
    }

    /**
     * Convert $words, an array of 5 bit numbres, to
     * bytes, an arrayof 8 bit numbers.
     *
     * @param  int  $numWords
     * @param  int[]  $words
     * @return int[]
     * @throws Base32Exception
     */
    public static function fromWords($numWords, array $words): array
    {
        return self::convertBits($words, $numWords, 5, 8, false);
    }

    /**
     * Converts words of $fromBits bits to $toBits bits in size.
     *
     * @param  int[]  $data  - character array of data to convert
     * @param  int  $inLen  - number of elements in array
     * @param  int  $fromBits  - word (bit count) size of provided data
     * @param  int  $toBits  - requested word size (bit count)
     * @param  bool  $pad  - whether to pad (only when encoding)
     * @return int[]
     * @throws Base32Exception
     */
    protected static function convertBits(array $data, $inLen, $fromBits, $toBits, $pad = true): array
    {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        $maxacc = (1 << ($fromBits + $toBits - 1)) - 1;

        for ($i = 0; $i < $inLen; $i++) {
            $value = $data[$i];
            if ($value < 0 || $value >> $fromBits) {
                throw new Base32Exception('Invalid value for convert bits');
            }

            $acc = (($acc << $fromBits) | $value) & $maxacc;
            $bits += $fromBits;

            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = (($acc >> $bits) & $maxv);
            }
        }

        if ($pad) {
            if ($bits) {
                $ret[] = ($acc << $toBits - $bits) & $maxv;
            }
        } else {
            if ($bits >= $fromBits || ((($acc << ($toBits - $bits))) & $maxv)) {
                throw new Base32Exception('Invalid data');
            }
        }

        return $ret;
    }
}