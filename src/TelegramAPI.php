<?php

namespace Jove;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Amp\Redis\Config;
use Amp\Redis\Redis;
use Amp\Redis\RemoteExecutor;
use Amp\Socket;
use Exception;
use Jove\Methods\MethodsFactory;
use Jove\Middlewares\AuthorizeWebhooks;
use Jove\Request\Client;
use Jove\Types\Update;
use Medoo\DatabaseConnection;
use Monolog\Logger;
use function Amp\call;
use function Medoo\connect;
use const SIGINT;
use const STDOUT;

class TelegramAPI
{
    public Client $client;
    private MethodsFactory $methodFactory;
    private static array $databases = [];
    private static ?EventHandler $eventHandler = null;
    public ?DatabaseConnection $database;
    public Utils\Logger\Logger $logger;
    public ?Redis $redis;
    private array $eventHandlers = [];

    public function __construct(array $config = [])
    {
        config([
            'telegram' => array_merge(
                config('telegram'), $config
            )
        ]);
        $this->enableRedis();
        $this->enableDatabase();
        $this->logger = new Utils\Logger\Logger(config('app.logger_level'), config('app.logger_type'));
        $this->client = new Client();
        $this->methodFactory = new MethodsFactory($this);
        static::$eventHandler ??= tap(new EventHandler(), function ($eventHandler) {
            $eventHandler->setAPI($this);
        });
    }

    private function enableRedis(): void
    {
        $config = config('redis');

        if (isset($config['host']) && isset($config['port'])) {
            $uri = Config::fromUri('redis://' . $config['host'] . ':' . $config['port'] . '?' . http_build_query([
                    'password' => $config['password'] ?? '',
                    'timeout' => $config['timeout'] ?? '',
                    'database' => $config['database'] ?? 0,
                ]));

            $this->redis = new Redis(new RemoteExecutor($uri));
        }

    }

    public function getRedis(): ?Redis
    {
        return $this->redis;
    }

    private function enableDatabase($driver = null): void
    {
        $driver ??= config('database.default');

        static::$databases[$driver] ??= value(function () use ($driver) {
            $connections = config('database.connections');

            if (isset($connections[$driver]) && $options = $connections[$driver]) {
                return $this->database = connect(array_shift($options), $options);
            }

            return null;
        });
    }

    public function getDatabase(): ?DatabaseConnection
    {
        return $this->database;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public static function factory(array $config = []): TelegramAPI
    {
        return new static($config);
    }

    /**
     * Dynamic proxy Telegram methods
     *
     * @param string $name
     * @param array $arguments
     * @return Promise
     * @throws Exception
     */
    public function __call(string $name, array $arguments = [])
    {
        return call_user_func_array([$this->methodFactory, $name], $arguments);
    }

    /**
     * Prepare event handler for hearing new incoming updates
     *
     * @param int $updateType
     * @param string $uri
     * @throws Exception
     */
    public function hear(int $updateType = EventHandler::UPDATE_TYPE_WEBHOOK, string $uri = '/')
    {
        Loop::run(function () use ($updateType, $uri) {
            if (!is_null(static::$eventHandler)) {
                array_unshift($this->eventHandlers, static::$eventHandler);
            }

            $forceRunIn = function ($mode) {
                $isCli = env('APP_RUNNING_IN_CONSOLE') || in_array(PHP_SAPI, ['cli', 'php-dbg']);

                switch ($mode) {
                    case 'cli':
                        if (!$isCli) {
                            throw new Exception('You must use this type in cli');
                        }
                        break;
                    case 'browser':
                        if ($isCli) {
                            throw new Exception('You must use this type in cli');
                        }
                }
            };

            switch ($updateType) {
                case EventHandler::UPDATE_TYPE_WEBHOOK:
                    $forceRunIn('browser');
                    $this->finish();
                    $update = new Update(json_decode(file_get_contents('php://input'), true) ?? []);
                    yield gather(array_map(
                        fn($eventHandler) => $eventHandler->boot(tap($update, function ($update) {
                            $update->setApi($this);
                        })), $this->eventHandlers
                    ));
                    break;
                case EventHandler::UPDATE_TYPE_POLLING:
                    $forceRunIn('cli');
                    $offset = -1;
                    yield $this->deleteWebhook();

                    Loop::repeat(config('telegram.loop_interval'), function () use (&$offset) {
                        $updates = yield $this->getUpdates($offset);

                        if (is_collection($updates) && $updates->isNotEmpty()) {
                            foreach ($updates as $update) {
                                yield gather(array_map(
                                    fn($eventHandler) => $eventHandler->boot(tap($update, function ($update) {
                                        $update->setApi($this);
                                    })), $this->eventHandlers
                                ));

                                $offset = $update->update_id + 1;
                            }
                        }

                    });

                    Loop::onSignal(SIGINT, function (string $watcherId) {
                        Loop::cancel($watcherId);
                        exit();
                    });
                    break;
                case EventHandler::UPDATE_TYPE_SOCKET_SERVER:
                    $options = getopt('d::', [
                        'drop_pending_updates::'
                    ]);

                    yield $this->resetWebhook([
                        'drop_pending_updates' => (bool)($options['d'] ?? $options['drop_pending_updates'] ?? false)
                    ]);

                    $forceRunIn('cli');

                    $host = config('telegram.socket_server.host');
                    $port = config('telegram.socket_server.port');

                    $servers = [
                        Socket\Server::listen("{$host}:{$port}"),
                        Socket\Server::listen('[::]:' . $port),
                    ];

                    $logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
                    $logHandler->setFormatter(new ConsoleFormatter);
                    $logger = new Logger('server');
                    $logger->pushHandler($logHandler);
                    $router = new Server\Router();

                    foreach (['GET', 'POST'] as $method)
                        $router->addRoute($method, $uri, Server\Middleware\stack(
                            new CallableRequestHandler(function (Server\Request $request) {
                                $update = new Update(
                                    json_decode(yield $request->getBody()->buffer(), true) ?? []
                                );

                                gather(array_map(
                                    fn($eventHandler) => call(
                                        fn() => yield $eventHandler->boot(tap($update, function ($update) {
                                            $update->setApi($this);
                                        }))
                                    ), $this->eventHandlers
                                ));
                                return new Response(Status::OK, stringOrStream: 'HTTP Ok');
                            }),
                            new AuthorizeWebhooks()
                        ));

                    $server = new Server\Server($servers, $router, $logger);

                    yield $server->start();

                    Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
                        Loop::cancel($watcherId);
                        yield $server->stop();
                    });
                    break;
                default:
                    throw new Exception('Unsupported update handling type.');
            }
        });
    }

    /**
     * Finish browser requests
     *
     * @param string $message
     * @return void
     */
    public function finish(string $message = 'HTTP OK')
    {
        while (ob_get_level() > 0)
            ob_end_clean();
        @header('Connection: close');
        ignore_user_abort(true);
        ob_start();
        print $message;
        $size = ob_get_length();
        @header("Content-Length: $size");
        @header('Content-Type: application/json');
        ob_end_flush();
        flush();
        if (function_exists('litespeed_finish_request'))
            litespeed_finish_request();
        if (function_exists('fastcgi_finish_request'))
            fastcgi_finish_request();
    }

    /**
     * A decorator for defining events
     *
     * @param $event
     * @param ?callable $listener
     * @return void
     */
    public function on($event, ?callable $listener = null)
    {
        if (is_callable($event)) {
            [$event, $listener] = ['any', $event];
        }

        static::$eventHandler->on($event, $listener);
    }

    /**
     * Set the event handler for avoiding updates
     *
     * @param $eventHandler
     * @return Promise
     * @throws Exception
     */
    public function setEventHandler($eventHandler): Promise
    {
        return call(function () use ($eventHandler) {
            $eventHandler = new $eventHandler();
            $eventHandler->setApi($this);
            if ($eventHandler instanceof EventHandler) {
                $this->eventHandlers[] = $eventHandler;
                yield call([$eventHandler, 'onStart']);
            } else {
                throw new Exception(sprintf(
                    'The eventHandler must be instance of %s', EventHandler::class,
                ));
            }
        });
    }
}
