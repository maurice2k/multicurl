<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Maurice\Multicurl\{Manager, Channel, HttpChannel, Helper\Stream};

// Create a cookie jar file in a writable directory
$cookieJarFile = sys_get_temp_dir() . '/multicurl_cookies_' . uniqid() . '.txt';

$counter = new \stdClass();
$counter->links = 20;

$manager = new Manager(2);  // allow two concurrent connections
$manager->setContext($counter);

$chan = new HttpChannel('https://en.wikipedia.org/wiki/Web_crawler');
$chan->setConnectionTimeout(500);
$chan->setTimeout(5000);
$chan->setFollowRedirects(true);
$chan->setCookieJarFile($cookieJarFile);

$chan->setOnReadyCallback(function(Channel $channel, array $info, Stream $stream, Manager $manager) {
    $length = $stream->getLength();
    $streamContent = $stream->consume();
    echo "[X] Successfully loaded '" . $channel->getURL() . "' (" . $length . " bytes)\n";

    if ($manager->getContext()->links > 0) {

        if (!preg_match_all('#<a[^>]+?href="(/wiki/[^:]+?)"[^>]*?>#', $streamContent, $matches)) {
            return;
        }

        $relativeLinks = array_unique($matches[1]);
        shuffle($relativeLinks);
        $relativeLinks = array_slice($relativeLinks, 0, min($manager->getContext()->links, 5));

        foreach ($relativeLinks as $relativeLink) {

            $urlinfo = parse_url($info['url']);
            $newUrl = $urlinfo['scheme'] . '://' . $urlinfo['host'] . $relativeLink;

            $newChan = clone $channel;
            $newChan->setURL($newUrl);
            $manager->addChannel($newChan);

            $manager->getContext()->links--;

        }
    }
});

$chan->setOnTimeoutCallback(function(Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager) {
    echo "[T] " . ($timeoutType == Channel::TIMEOUT_CONNECTION ? "Connection" : "Global") . " timeout after {$elapsedMS} ms for '" . $channel->getURL() . "'\n";
});

$chan->setOnErrorCallback(function(Channel $channel, string $message, $errno, array $info, Manager $manager) {
    echo "[E] cURL error #{$errno}: '{$message}' for '" . $channel->getURL() . "'\n";
});

$manager->addChannel($chan);

$manager->run();

// Clean up
if (file_exists($cookieJarFile)) {
    unlink($cookieJarFile);
} 