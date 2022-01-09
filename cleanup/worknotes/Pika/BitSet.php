<?php
namespace Charm\Parsing\Pika;

class BitSet implements \Countable {

    const INT_SIZE = 64;
    private $bits = [];
    private $length;

    public function __construct(int $nbits=0) {
        $this->bits = array_fill(0, ceil($nbits / 64), 0);
        $this->length = $nbits;
    }

    public function get(int $bitIndex, int $toIndex=null) {
        if ($toIndex === null) {
            $bitOffset = ($bitIndex / 64) | 0;
            $bitIndex %= 64;
            return ($this->bits[$bitOffset] >> $bitIndex) & 0x1;
        }

        $result = array_fill(0, ceil(($toIndex - $bitIndex) / 64), 0);
        $dstBit = 0;
        for ($i = $bitIndex; $i < $toIndex; $i++) {
            $srcOffset = ($bitIndex / self::INT_SIZE) | 0;
            $srcBit = $bitIndex % self::INT_SIZE;
            $bit = ($this->bits[$bitOffset] >> $bitNumber) & 0b1;
            $dstOffset = ($dstBit / self::INT_SIZE) | 0;
            $result[$dstOffset] |= $bit << ($dstBit % self::INT_SIZE)
            $dstBit++;
        }

        $resultBitSet = clone $this;
        $resultBitSet->bits = $result;
        $resultBitSet->length = $dstBit;
        return $resultBitSet;
    }

    public function and(BitSet $set) {
        $l = strlen($this->bits);
        for ($i = 0; $i < $l; $i++) {
            $this->bits[$i] = 
        }
    }

}
// get bit N: (ord($this->bits[($n/self::INT_SIZE)|0]) >> ($n % self::INT_SIZE)) & 1;
// set bit N: $this->bits[($n/self::INT_SIZE)|0] |= 1 << ($n % self::INT_SIZE);
