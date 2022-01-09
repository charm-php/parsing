<?php
namespace Charm\Parsing\Util;

class ExceptionTools {

    private $ex;

    public function __construct(\Throwable $originalException) {
        $this->ex = $originalException;
    }

    /**
     * Prepends a stack trace level on the exception
     */
    public function pushStackTrace(array $t) {
        $rp = new \ReflectionProperty(\Exception::class, 'trace');
        $rp->setAccessible(true);
        $trace = $rp->getValue($this->ex);
        array_unshift($trace, $t);
        $rp->setValue($this->ex, $trace);
        $rp->setAccessible(false);
        return $this;
    }

    public function popStackTrace(): array {
        $rp = new \ReflectionProperty(\Exception::class, 'trace');
        $rp->setAccessible(true);
        $trace = $rp->getValue($this->ex);
        $top = array_shift($trace);
        $rp->setValue($this->ex, $trace);
        $rp->setAccessible(false);
        return $top;
    }

    public function setFile(string $filename) {
        $rp = new \ReflectionProperty($this->ex, 'file');
        $rp->setAccessible(true);
        $rp->setValue($this->ex, $filename);
        $rp->setAccessible(false);
        return $this;
    }

    public function setLine(int $line) {
        $rp = new \ReflectionProperty($this->ex, "line");
        $rp->setAccessible(true);
        $rp->setValue($this->ex, $line);
        $rp->setAccessible(false);
        return $this;
    }

    public function setCode($code) {
        $rp = new \ReflectionProperty($this->ex, "code");
        $rp->setAccessible(true);
        $rp->setValue($this->ex, $code);
        $rp->setAccessible(false);
        return $this;
    }

    public function setMessage($message) {
        $rp = new \ReflectionProperty($this->ex, "message");
        $rp->setAccessible(true);
        $rp->setValue($this->ex, $message);
        $rp->setAccessible(false);
        return $this;
    }

    public function getTrace() {
        $rp = new \ReflectionProperty($this->ex, 'trace');
        $rp->setAccessible(true);
        $trace = $rp->getValue($this->ex);
        $rp->setAccessible(false);
        return $trace;
    }

    public function setTrace(array $trace) {
        $rp = new \ReflectionProperty($this->ex, 'trace');
        $rp->setAccessible(true);
        $rp->setValue($this->ex, $trace);
        $rp->setAccessible(false);
    }

}
