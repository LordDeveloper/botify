<?php


use Jove\Types\Map\Message;
use Jove\Utils\Plugins\Plugin;

return Plugin::apply(function (Message $message) {
    if ($message->command(['ping', 'botify', 'botty'])) {
        $mt = microtime(true);
        $replied = yield $message->reply('Please wait ...');
        yield $replied->edit('Ping took time: ' . round((microtime(true) - $mt) * 1000, 3) . ' ms');
    }
});