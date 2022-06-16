<?php

namespace Jove;

use Amp\Http\Client\Body\FormBody;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Promise;
use Jove\Methods\Methods;
use function Amp\call;

class TelegramAPI
{
    use Methods;

    private static $client;

    /**
     * @param $uri
     * @param array $attributes
     * @return Promise
     */
    protected function post($uri, array $attributes = []): Promise
    {
        return call(function () use ($uri, $attributes) {
            $client = static::$client ??= HttpClientBuilder::buildDefault();
            $response = yield $client->request(
                $this->generateRequest($uri, $attributes)
            );
            return json_decode(
                yield $response->getBody()->buffer(), true
            );
        });
    }

    /**
     * @param $uri
     * @param array $data
     * @return Request
     */
    private function generateRequest($uri, array $data = []): Request
    {
        $request = new Request(
            $this->generateUri($uri, $data), 'POST'
        );
//        $request->setBody(
//            $this->generateBody($data)
//        );
        $request->addHeader('Content-Type', 'multipart/form-data');
        return $request;
    }

    /**
     * @param array $fields
     * @return FormBody
     */
    private function generateBody(array $fields): FormBody
    {
        $body = new FormBody();
        $fields = array_filter($fields);

        foreach ($fields as $fieldName => $content)
            if (is_string($content) && file_exists($content) && filesize($content) > 0)
                $body->addFile($fieldName, $content);
            else
                $body->addField($fieldName, $content);

        return $body;
    }

    /**
     * @param $uri
     * @param array $queries
     * @return string
     */
    private function generateUri($uri, array $queries = []): string
    {
        $url = sprintf('https://api.telegram.org/bot%s/', getenv('BOT_TOKEN'));

        if (! empty($uri))
            $url .= ltrim($uri, '/');

        if (! empty($queries))
            $url .= '?'. http_build_query($queries);

        return $url;
    }

//    public function __call($name, array $arguments = [])
//    {
//        $name = strtolower($name);
//
//        if(str_ends_with($name, 'async')) {
//            $name = substr($name, 0, -5);
//            dump($name);
//            array_unshift($arguments, $name);
//
//            return call(
//                fn () => yield $this->post(... $arguments)
//            );
//        }
//    }
}