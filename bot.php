<?php


use Botify\Events\EventHandler;
use Botify\TelegramAPI;
use Botify\Types\Map\Message;

require_once __DIR__ .'/bootstrap/app.php';

// You must put your token in .env file, Make sure the .env file is exists!
$bot = TelegramAPI::factory();

$bot->setEventHandler(new class extends EventHandler {
    public function onUpdateNewMessage(Message $message)
    {
        if (isset($message['reply_to_message'])) {
            $replies = $message['reply_to_message']->getReplies();
            while (yield $replies->advance()) {
                var_dump($replies->getCurrent()->text);
            }
        }

        yield $message->reply("Hi {$message['from']['first_name']} :)");
    }
});

$bot->on('mention', function (Message $message) {
    yield $message->reply('جانم');
}).
// You can use different update handing types like UPDATE_TYPE_WEBHOOK,
// UPDATE_TYPE_HTTP_SERVER and UPDATE_TYPE_LONG_POLLING
// Note: default type is UPDATE_TYPE_WEBHOOK.
$bot->loopAndHear(\Botify\Events\Handler::UPDATE_TYPE_POLLING);