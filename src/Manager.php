<?php
declare(strict_types = 1);

/**
 * Multicurl -- Object based asynchronous multi-curl wrapper
 *
 * Copyright (c) 2018-2021 Moritz Fain
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
     *
     * @var int
     */
    protected $maxConcurrency = 10;

    /**
     * Channel queue
     *
     * @var array
     */
    protected $channelQueue = [];

    /**
     * Lookup table for cURL resources to Channel instances
     *
     * @var array
     */
    protected $resourceChannelLookup = [];

    /**
     * Low watermark factor
     *
     * The low watermark for the channel queue is reached when there are less
     * than $maxConcurrency * $lowWatermarkFactor items left in the queue.
     *
     * @var integer
     */
    protected $lowWatermarkFactor = 2;

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
     * @var callable
     */
    protected $refillCallback;

    /**
     * Multi-Curl handle
     *
     * @var \CurlMultiHandle
     */
    protected $mh;

    /**
     * Delay queue
     *
     * @var array
     */
    protected $delayQueue = [];

    /**
     * Is delay queue sorted?
     *
     * @var bool
     */
    protected $delayQueueSorted = false;


    /**
     * Constructor
     *
     * @param integer $maxConcurrency Max. concurrency (default 10)
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
     * @return void
     */
    public function addChannel(Channel $channel, bool $unshift = false, $minDelay = 0.0)
    {
        if ($minDelay > 0) {
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
     *
     * @param int $maxConcurrency
     * @return void
     */
    public function setMaxConcurrency(int $maxConcurrency = 1)
    {
        if ($maxConcurrency < 1) {
            $maxConcurrency = 1;
        }

        $this->maxConcurrency = $maxConcurrency;
    }

    /**
     * Called when the queue reached the low watermark
     */
    protected function onQueueLowWatermark()
    {
        if (isset($this->refillCallback)) {
            call_user_func($this->refillCallback, count($this->channelQueue), $this->maxConcurrency);
        }
    }

    /**
     * Sets refill queue callback
     *
     * @param callable $refillCallback
     * @return void
     */
    public function setRefillCallback(callable $refillCallback)
    {
        $this->refillCallback = $refillCallback;
    }

    /**
     * Process channels using multi-curl
     *
     * @return void
     */
    public function run()
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
                            /** @var Channel $channel */
                            $channel = $this->resourceChannelLookup[(int)$ch];
                            $info = curl_getinfo($ch);

                            if ($multiInfo['result'] === CURLE_OK) {
                                $content = curl_multi_getcontent($ch);
                                $channel->onReady($info, $content, $this);

                            } else if ($multiInfo['result'] === CURLE_OPERATION_TIMEOUTED) {
                                if ($info['connect_time'] > 0 && $info['pretransfer_time'] > 0) {
                                    $channel->onTimeout(Channel::TIMEOUT_TOTAL, (int)($info['total_time'] * 1000), $this);
                                } else {
                                    $channel->onTimeout(Channel::TIMEOUT_CONNECTION, (int)($info['total_time'] * 1000), $this);
                                }

                            } else {
                                $channel->onError(curl_strerror($multiInfo['result']), $multiInfo['result'], $info, $this);
                            }

                            unset($this->resourceChannelLookup[(int)$ch]);
                            curl_multi_remove_handle($this->mh, $ch);
                            curl_close($ch);
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
            usort($this->delayQueue, function($a, $b) { return $a[2] - $b[2]; });
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
     * @param integer $number Maximum number of channels to add
     * @return int The number of effectively added channels
     */
    protected function addNCurlResourcesToMultiCurl(int $number): int
    {
        $added = 0;
        foreach (array_splice($this->channelQueue, 0, $number) as $channel) {
            /** @var Channel $channel */
            $ch = $this->createCurlResourceFromChannel($channel);
            curl_multi_add_handle($this->mh, $ch);
            $this->resourceChannelLookup[(int)$ch] = $channel;
            $added++;
        }
        return $added;
    }

    /**
     * Creates curl channel resource from Channel instance
     *
     * @param Channel $channel
     * @return \CurlHandle
     */
    protected function createCurlResourceFromChannel(Channel $channel)
    {
        $ch = curl_init();

        foreach ($channel->getCurlOptions() as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return $ch;
    }
}
