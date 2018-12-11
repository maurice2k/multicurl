<?php
declare(strict_types = 1);

/**
 * Multicurl -- Object based asynchronous multi-curl wrapper
 *
 * Copyright (c) 2018 Moritz Fain
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
    protected $maxConcurrency = null;

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
     * @var resource
     */
    protected $mh;

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
     * @return void
     */
    public function addChannel(Channel $channel, bool $unshift = false)
    {
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
            $this->refillCallback(count($this->$channelQueue), $this->maxConcurrency);
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
        $active = null;

        $numAdded = $this->addNCurlResourcesToMultiCurl($this->maxConcurrency);

        do {
            $mrc = curl_multi_exec($this->mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc === CURLM_OK) {

            if (curl_multi_select($this->mh) !== -1) {

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
                                $channel->onReady($info, $content);

                            } else if ($multiInfo['result'] === CURLE_OPERATION_TIMEOUTED) {

                                if ($info['connect_time'] > 0 && $info['pretransfer_time'] > 0) {
                                    $channel->onTimeout(Channel::TIMEOUT_TOTAL, (int)($info['total_time'] * 1000), $this);
                                } else {
                                    $channel->onTimeout(Channel::TIMEOUT_CONNECTION, (int)($info['total_time'] * 1000), $this);
                                }

                            } else {
                                $channel->onError(curl_strerror($multiInfo['result']), $multiInfo['result'], $info);
                            }

                            unset($this->resourceChannelLookup[(int)$ch]);
                            curl_multi_remove_handle($this->mh, $ch);
                        }

                    } while ($msgInQueue > 0);

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
        }

        curl_multi_close($this->mh);
    }

    /**
     * Adds channels from the queue to multi-curl
     *
     * @param integer $number Maximum number of channels to add
     * @return int The number of effectively added channels
     */
    protected function addNCurlResourcesToMultiCurl(int $number)
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
     * @return resource
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
