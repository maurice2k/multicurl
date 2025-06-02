<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Maurice\Multicurl\{Manager, Channel, HttpChannel, Helper\Stream};

// Create a cookie jar file in a writable directory
$cookieJarFile = sys_get_temp_dir() . '/multicurl_cookies_' . uniqid() . '.txt';

$urls = [
    'https://www.google.com/',
    'https://www.facebook.com/',
    'https://www.amazon.com/',
    'https://www.ebay.com/',
    'https://www.example.org/',
    'https://non-existant.this-is-a-dns-error.org/',
    'https://www.netflix.com/',
    'https://www.microsoft.com/',
];

$manager = new Manager(2);  // allow two concurrent connections

// set defaults for all channels that are being instantiated using HttpChannel::create()
HttpChannel::prototype()->setConnectionTimeout(200);
HttpChannel::prototype()->setTimeout(5000);
HttpChannel::prototype()->setFollowRedirects(true);
HttpChannel::prototype()->setCookieJarFile($cookieJarFile);

foreach ($urls as $url) {

    $chan = HttpChannel::create($url);

    $chan->setOnReadyCallback(function(Channel $channel, array $info, Stream $stream, Manager $manager) {
        $length = $stream->getLength();
        $streamContent = $stream->consume();
        echo "[X] Successfully loaded '" . $channel->getURL() . "' (" . $length . " bytes, status code " . $info['http_code'] . ")\n";
    });

    $chan->setOnTimeoutCallback(function(Channel $channel, int $timeoutType, int $elapsedMS, Manager $manager) {
        echo "[T] " . ($timeoutType == Channel::TIMEOUT_CONNECTION ? "Connection" : "Global") . " timeout after {$elapsedMS} ms for '" . $channel->getURL() . "'\n";
    });

    $chan->setOnErrorCallback(function(Channel $channel, string $message, $errno, array $info, Manager $manager) {
        echo "[E] cURL error #{$errno}: '{$message}' for '" . $channel->getURL() . "'\n";
    });

    $manager->addChannel($chan);
}

$manager->run();

// Clean up
if (file_exists($cookieJarFile)) {
    unlink($cookieJarFile);
} 