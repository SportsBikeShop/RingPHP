<?php
namespace GuzzleHttp\Ring\Future;

use GuzzleHttp\Ring\Exception\CancelledFutureAccessException;
use GuzzleHttp\Ring\Exception\RingException;
use React\Promise\PromiseInterface;

/**
 * Implements common future functionality built on top of promises.
 */
abstract class BaseFutureTrait
{
    /** @var callable */
    public $waitfn;

    /** @var callable */
    private $cancelfn;

    /** @var PromiseInterface */
    private $wrappedPromise;

    /** @var \Exception Error encountered. */
    public $error;

    /** @var mixed Result of the future */
    public $result;

    public $isRealized = false;

    /**
     * @param PromiseInterface $promise Promise to shadow with the future.
     * @param callable         $wait    Function that blocks until the deferred
     *                                  computation has been resolved. This
     *                                  function MUST resolve the deferred value
     *                                  associated with the supplied promise.
     * @param callable         $cancel  If possible and reasonable, provide a
     *                                  function that can be used to cancel the
     *                                  future from completing.
     */
    public function __construct(
        PromiseInterface $promise,
        $wait = null,
        $cancel = null
    ) {
        $this->wrappedPromise = $promise;
        $this->waitfn = $wait;
        $this->cancelfn = $cancel;
    }

    public function wait()
    {
        if (!$this->isRealized) {
            $this->addShadow();
            if (!$this->isRealized && $this->waitfn) {
                $this->invokeWait();
            }
            if (!$this->isRealized) {
                $this->error = new RingException('Waiting did not resolve future');
            }
        }

        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }

    public function promise()
    {
        return $this->wrappedPromise;
    }

    public function then(
        $onFulfilled = null,
        $onRejected = null,
        $onProgress = null
    ) {
        return $this->wrappedPromise->then($onFulfilled, $onRejected, $onProgress);
    }

    public function cancel()
    {
        if (!$this->isRealized) {
            $cancelfn = $this->cancelfn;
            $this->waitfn = $this->cancelfn = null;
            $this->isRealized = true;
            $this->error = new CancelledFutureAccessException();
            if ($cancelfn) {
                $cancelfn($this);
            }
        }
    }

    private function addShadow()
    {
        // Get the result and error when the promise is resolved. Note that
        // calling this function might trigger the resolution immediately.
        $that = $this;
        $this->wrappedPromise->then(
            function ($value) use ($that) {
                $that->isRealized = true;
                $that->result = $value;
                $that->waitfn = $that->cancelfn = null;
            },
            function ($error) use ($that) {
                $that->isRealized = true;
                $that->error = $error;
                $that->waitfn = $that->cancelfn = null;
            }
        );
    }

    private function invokeWait()
    {
        try {
            $wait = $this->waitfn;
            $this->waitfn = null;
            $wait();
        } catch (\Exception $e) {
            // Defer can throw to reject.
            $this->error = $e;
            $this->isRealized = true;
        }
    }
}
