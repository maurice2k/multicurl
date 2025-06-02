<?php
declare(strict_types = 1);

/**
 * Multicurl -- Object based asynchronous multi-curl wrapper
 *
 * Copyright (c) 2018-2025 Moritz Fain
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Maurice\Multicurl;

use CurlHandle;
use CurlMultiHandle;
use Maurice\Multicurl\Helper\ContextInfo;

/**
 * Manages the channel queue and concurrency limits
 *
 * @author Moritz Fain <moritz@fain.io>
 */
class Manager
{
    use ContextInfo;

    /**
     * Maximum number of concurrent channels
     */
    protected int $maxConcurrency = 10;

    /**
     * Channel queue
     *
     * @var array<array-key, Channel>
     */
    protected array $channelQueue = [];

    /**
     * Lookup table for cURL resources to Channel instances
     *
     * @var array<array-key, Channel>
     */
    protected array $resourceChannelLookup = [];

    /**
     * Low watermark factor
     *
     * The low watermark for the channel queue is reached when there are less
     * than $maxConcurrency * $lowWatermarkFactor items left in the queue.
     */
    protected int $lowWatermarkFactor = 2;

    /**
     * Queue refill callback
     *
     * The callback is called whenever the low watermark for the channel
     * queue is reached.
     *
     * Parameters:
     *   - int $queueSize
     *   - int $maxConcurrency
     *
     * @var callable(int $queueSize, int $maxConcurrency): mixed|null
     */
    protected $refillCallback = null;

    /**
     * Multi-Curl handle
     */
    protected \CurlMultiHandle $mh;

    /**
     * Delay queue
     *
     * @var array<array-key, array{0: Channel, 1: bool, 2: float}>
     */
    protected array $delayQueue = [];

    /**
     * Is delay queue sorted?
     */
    protected bool $delayQueueSorted = false;


    /**
     * Constructor
     *
     * @param int $maxConcurrency Max. concurrency (default 10)
     */
    public function __construct(int $maxConcurrency = 10)
    {
        $this->setMaxConcurrency($maxConcurrency);
    }

    /**
     * Adds a channel to the queue
     *
     * @param Channel $channel
     * @param bool $unshift Whether to add the channel to the beginning of the queue
     * @param float $minDelay Minimum delay (in seconds) before channel is getting active
     */
    public function addChannel(Channel $channel, bool $unshift = false, float $minDelay = 0.0): void
    {
        if ($minDelay > 0.0) {
            $this->delayQueue[] = [$channel, $unshift, microtime(true) + $minDelay];
            $this->delayQueueSorted = false;
            return;
        }

        if ($unshift) {
            array_unshift($this->channelQueue, $channel);
            return;
        }
        $this->channelQueue[] = $channel;
    }

    /**
     * Sets maximum number of concurrent channels
     */
    public function setMaxConcurrency(int $maxConcurrency = 1): void
    {
        if ($maxConcurrency < 1) {
            $maxConcurrency = 1;
        }

        $this->maxConcurrency = $maxConcurrency;
    }

    /**
     * Called when the queue reached the low watermark
     */
    protected function onQueueLowWatermark(): void
    {
        if (isset($this->refillCallback)) {
            call_user_func($this->refillCallback, count($this->channelQueue), $this->maxConcurrency);
        }
    }

    /**
     * Sets refill queue callback
     *
     * @param callable(int $queueSize, int $maxConcurrency): mixed $refillCallback
     */
    public function setRefillCallback(callable $refillCallback): void
    {
        $this->refillCallback = $refillCallback;
    }

    /**
     * Process channels using multi-curl
     */
    public function run(): void
    {
        $this->mh = curl_multi_init();

        loop:
        $active = null;
        $this->addNCurlResourcesToMultiCurl($this->maxConcurrency);

        do {
            $mrc = curl_multi_exec($this->mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        do {

            if (curl_multi_select($this->mh, (count($this->delayQueue) > 0 ? 0.1 : 1.0)) !== -1) {

                do {

                    $mrc = curl_multi_exec($this->mh, $active);

                    do {

                        $multiInfo = curl_multi_info_read($this->mh, $msgInQueue);

                        if ($multiInfo === false) {
                            break;
                        }

                        if ($multiInfo['msg'] === CURLMSG_DONE) {

                            $ch = $multiInfo['handle'];
                            unset($multiInfo['handle']);

                            /** @var Channel $channel */
                            $channel = $this->resourceChannelLookup[self::toHandleIdentifier($ch)];
                            $info = curl_getinfo($ch);

                            if ($multiInfo['result'] === CURLE_OK || ($multiInfo['result'] === CURLE_WRITE_ERROR && $channel->isStreamAborted())) {
                                $content = curl_multi_getcontent($ch);

                                $channel->onReady($info, $content ?? '', $this);
                            } else if ($multiInfo['result'] === CURLE_OPERATION_TIMEOUTED) {
                                if ($info['connect_time'] > 0 && $info['pretransfer_time'] > 0) {
                                    $channel->onTimeout(Channel::TIMEOUT_TOTAL, (int)($info['total_time'] * 1000), $this);
                                } else {
                                    $channel->onTimeout(Channel::TIMEOUT_CONNECTION, (int)($info['total_time'] * 1000), $this);
                                }

                            } else {
                                $channel->onError(curl_strerror($multiInfo['result']), $multiInfo['result'], $info, $this);
                            }

                            // Remove channel from resource lookup
                            $this->closeChannel($channel);
                            unset($ch);
                        }


                    } while ($msgInQueue > 0);

                    $this->processDelayQueue();

                    // Check queue low watermark
                    if (count($this->channelQueue) < $this->maxConcurrency * $this->lowWatermarkFactor) {
                        $this->onQueueLowWatermark();
                    }

                    // Add new channels from the queue if not yet exhausted
                    if (count($this->channelQueue) > 0) {
                        if ($this->addNCurlResourcesToMultiCurl($this->maxConcurrency - $active) > 0) {
                            $mrc = CURLM_CALL_MULTI_PERFORM;
                        }
                    }

                } while ($mrc === CURLM_CALL_MULTI_PERFORM);

            }

        } while ($active && $mrc === CURLM_OK);

        $delayToFirstChannel = $this->processDelayQueue();
        if ($delayToFirstChannel !== null) {
            if ($delayToFirstChannel > 0) {
                usleep($delayToFirstChannel);
            }

            goto loop;
        }

        curl_multi_close($this->mh);
        unset($this->mh);
    }

    /**
     * Processes delay queue and adds due channels to the standard queue
     *
     * @return int|null Returns either null if there are no pending channels
     * in the delay queue or the relative delay in microseconds to the first
     * channel in the delay queue
     */
    protected function processDelayQueue(): ?int
    {
        $delayQueueCount = count($this->delayQueue);
        if ($delayQueueCount == 0) {
            return null;
        }

        if (!$this->delayQueueSorted) {
            usort($this->delayQueue, function($a, $b) { return $a[2] <=> $b[2]; });
            $this->delayQueueSorted = true;
        }

        $now = microtime(true);
        $added = 0;
        for ($i = 0; $i < $delayQueueCount; $i++) {
            if ($this->delayQueue[$i][2] > $now) {
                break;
            }
            $this->addChannel($this->delayQueue[$i][0], $this->delayQueue[$i][1]);
            $added++;
        }

        $delayToFirstChannel = (int)(($this->delayQueue[0][2] - $now) * 1000000);

        if ($added > 0) {
            // remove added channels from delay queue
            array_splice($this->delayQueue, 0, $added);
        }

        return $delayToFirstChannel;
    }

    /**
     * Adds channels from the queue to multi-curl
     *
     * @param int $number Maximum number of channels to add
     * @return int The number of effectively added channels
     */
    protected function addNCurlResourcesToMultiCurl(int $number): int
    {
        $added = 0;
        foreach (array_splice($this->channelQueue, 0, $number) as $channel) {
            /** @var Channel $channel */
            $beforeChannel = $channel->popBeforeChannel();
            if ($beforeChannel instanceof Channel) {
                $channelToProcess = $beforeChannel;
            } else {
                $channelToProcess = $channel;
            }
            
            $ch = $this->createCurlHandleFromChannel($channelToProcess);
            curl_multi_add_handle($this->mh, $ch);
            $this->resourceChannelLookup[self::toHandleIdentifier($ch)] = $channelToProcess;
            $added++;
            
            // If we've reached our limit, stop processing
            if ($added >= $number) {
                break;
            }
        }
        
        return $added;
    }

    /**
     * Create CurlHandle from Channel instance
     */
    protected function createCurlHandleFromChannel(Channel $channel): \CurlHandle
    {
        $ch = curl_init();

        foreach ($channel->getCurlOptions() as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        if ($channel->isStreamable()) {
            // For streamable channels, don't return content and set up write callback
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use ($channel) {
                return $channel->onStream($data, $this);
            });
        } else {
            // Only set CURLOPT_RETURNTRANSFER for non-streamable channels
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        if ($channel instanceof HttpChannel && $channel->isShowCurlCommand()) {
            echo "--[ CURL COMMAND ]--------------------------------\n";
            echo $channel->generateCurlCommand() . "\n";
            echo "--------------------------------------------------\n";
        }

        $channel->setCurlHandle($ch);

        return $ch;
    }

    /**
     * Converts a curl handle to a unique identifier
     */
    protected static function toHandleIdentifier(\CurlHandle $handle): int
    {
        return spl_object_id($handle);
    }

    /**
     * Closes a specific channel and removes it from the multi-curl stack.
     */
    public function closeChannel(Channel $channel): void
    {
        $ch = $channel->getCurlHandle();

        // If the handle is already null (e.g., channel already closed), do nothing.
        if ($ch === null) {
            return;
        }

        if (isset($this->resourceChannelLookup[self::toHandleIdentifier($ch)])) {
            unset($this->resourceChannelLookup[self::toHandleIdentifier($ch)]);
        }

        if (isset($this->mh) && $this->mh instanceof \CurlMultiHandle) {
            curl_multi_remove_handle($this->mh, $ch);
        }

        curl_close($ch);
        $channel->setCurlHandle(null);

        $nextChannel = $channel->popNextChannel();
        if ($nextChannel !== null) {
            $this->addChannel($nextChannel);
        }
    }
}
