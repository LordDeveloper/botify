<?php

use Amp\Delayed;
use Amp\Promise;
use Jove\Utils\Button;
use Jove\Utils\Collection;
use Jove\Utils\Config;
use Jove\Utils\Dotty;

if (!function_exists('retry')) {
    /**
     * @param $times
     * @param callable $callback
     * @param int $sleep
     * @return mixed
     * @throws Exception
     */
    function retry($times, callable $callback, int $sleep = 0): mixed
    {
        static $attempts = 0;
        beginning:
        $attempts++;
        $times--;
        try {
            return $callback($attempts);
        } catch (Exception $e) {
            if ($times < 1) throw $e;
            $sleep && sleep($sleep);
            goto beginning;
        }
    }
}

if (!function_exists('tap')) {
    /**
     * @param $value
     * @param $callback
     * @return mixed
     */
    function tap($value, $callback): mixed
    {
        $callback($value);

        return $value;
    }
}

if (!function_exists('gather')) {

    function gather($promises): Promise
    {
        return Promise\all($promises);
    }
}

if (!function_exists('ausleep')) {
    /**
     * @param $microseconds
     * @return Delayed
     */
    function ausleep($microseconds)
    {
        return new Delayed($microseconds);
    }
}

if (!function_exists('asleep')) {
    /**
     * @param $time
     * @return Promise
     */
    function asleep($time): Promise
    {
        return ausleep($time * 1000);
    }
}

if (!function_exists('collect')) {
    /**
     * @param array $items
     * @param bool $recursive
     * @return Collection
     */
    function collect(array $items, bool $recursive = false): Collection
    {
        if ($recursive === true) {
            foreach ($items as &$item) {
                if (is_array($item)) {
                    $item = collect($item);
                }
            }
        }
        return new Collection($items);
    }
}

if (!function_exists('is_collection')) {
    /**
     * Check the $value is a collection
     *
     * @param $value
     * @return bool
     */
    function is_collection($value): bool
    {
        return $value instanceof Collection;
    }
}

if (!function_exists('base_path')) {
    /**
     * Resolve base path
     *
     * @param string $path
     * @return string
     */
    function base_path(string $path = ''): string
    {
        return abs_path(rtrim(__BASE_DIR__, '/') . '/' . ltrim($path, '/'));
    }
}

if (!function_exists('storage_path')) {
    /**
     * Resolve storage path
     *
     * @param string $path
     * @return string
     */
    function storage_path(string $path = ''): string
    {
        return base_path('/storage/' . trim($path, '/'));
    }
}

if (!function_exists('config_path')) {
    function config_path($path = ''): string
    {
        return base_path('/config/' . trim($path, '/'));
    }
}

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        return ($value = getenv($key)) ? value(function () use ($value) {
            return match (strtolower($value)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'empty', '(empty)' => '',
                'null', '(null)' => null,
                default => $value
            };
        }) : $default;
    }
}

if (!function_exists('value')) {
    function value($value, ...$args)
    {
        return $value instanceof Closure ? $value(... $args) : $value;
    }
}

if (!function_exists('array_exists')) {

    function array_exists($array, $key): bool
    {
        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }
}

if (!function_exists('array_accessible')) {

    function array_accessible($value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }
}

if (!function_exists('array_collapse')) {

    function array_collapse(iterable $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if (!is_array($values)) {
                continue;
            }

            $results[] = $values;
        }

        return array_merge([], ...$results);
    }
}

if (!function_exists('data_get')) {

    function data_get($target, $key, $default = null)
    {
        if (is_null($key)) {
            return value($default ?? $target);
        }

        $key = is_array($key) ? $key : explode('.', $key);

        foreach ($key as $i => $segment) {
            unset($key[$i]);

            if (is_null($segment)) {
                return $target;
            }

            if ($segment === '*') {
                if (!is_array($target)) {
                    return value($default);
                }

                $result = [];

                foreach ($target as $item) {
                    $result[] = data_get($item, $key);
                }

                return in_array('*', $key) ? array_collapse($result) : $result;
            }

            if (array_accessible($target) && array_exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return value($default);
            }
        }

        return $target;
    }
}

if (!function_exists('array_set')) {

    function array_set(array &$array, ?string $key, $value): array
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);

        foreach ($keys as $i => $key) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }
}

if (!function_exists('data_set')) {
    function data_set(&$target, $key, $value, bool $overwrite = true)
    {
        $segments = is_array($key) ? $key : explode('.', $key);

        if (($segment = array_shift($segments)) === '*') {
            if (!array_accessible($target)) {
                $target = [];
            }

            if ($segments) {
                foreach ($target as &$inner) {
                    data_set($inner, $segments, $value, $overwrite);
                }
            } elseif ($overwrite) {
                foreach ($target as &$inner) {
                    $inner = $value;
                }
            }
        } elseif (array_accessible($target)) {
            if ($segments) {
                if (!array_exists($target, $segment)) {
                    $target[$segment] = [];
                }

                data_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite || !array_exists($target, $segment)) {
                $target[$segment] = $value;
            }
        } elseif (is_object($target)) {
            if ($segments) {
                if (!isset($target->{$segment})) {
                    $target->{$segment} = [];
                }

                data_set($target->{$segment}, $segments, $value, $overwrite);
            } elseif ($overwrite || !isset($target->{$segment})) {
                $target->{$segment} = $value;
            }
        } else {
            $target = [];

            if ($segments) {
                data_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite) {
                $target[$segment] = $value;
            }
        }

        return $target;
    }
}

if (!function_exists('config')) {

    function config($id = null, $default = null)
    {
        static $config = null;
        $config ??= Config::make();

        if (is_null($id)) {
            return $config;
        }

        if (is_array($id)) {
            return $config->set($id);
        }

        return $config->get($id, $default);
    }
}

if (!function_exists('repeat')) {
    /**
     * Repeat a code n times
     * @param int $times
     * @param callable $callback
     * @param array $iterable
     * @param ...$args
     * @return array
     */
    function repeat(int $times, callable $callback, array $iterable = [], ...$args)
    {
        $returns = [];

        $iterable = array_pad($iterable, $times, '*');

        foreach ($iterable as $index => $item) {
            $returns[] = $callback($item, $index, ... $args);
        }
        return $returns;
    }
}

if (!function_exists('arepeat')) {
    /**
     * Asynchronous version of repeat
     *
     * @param int $times
     * @param callable $callback
     * @param array $iterable
     * @param mixed ...$args
     * @return Promise
     */
    function arepeat(int $times, callable $callback, array $iterable = [], ...$args): Promise
    {
        return gather(repeat($times, $callback, $iterable, ... $args));
    }
}

if (!function_exists('abs_path')) {
    /**
     * Get absolute path of a path
     * Unlike the realpath function, the output will not be false if it does not exist
     *
     * @param string $path
     * @return string
     */
    function abs_path(string $path): string
    {
        $segments = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = [];

        foreach ($segments as $segment) {
            if ('.' === $segment) continue;

            elseif ('..' === $segment) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $segment;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }
}

if (!function_exists('sprintln')) {
    function sprintln(...$vars)
    {
        return implode(PHP_EOL, $vars) . PHP_EOL;
    }
}

if (!function_exists('println')) {
    function println(...$vars)
    {
        echo sprintln(... $vars);
    }
}

if (!function_exists('is_json')) {
    function is_json($value): bool
    {
        return is_string($value) && is_array(json_decode($value, true));
    }
}

if (!function_exists('array_some')) {
    function array_some(array $array, ?callable $fn = null): bool
    {
        foreach ($array as $index => $item) {
            if (is_callable($fn) ? $fn($item, $index) : $item) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('array_every')) {
    function array_every(array $array, ?callable $fn = null): bool
    {
        foreach ($array as $index => $item) {
            if (!(is_callable($fn) ? $fn($item, $index) : $item)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('button')) {
    /**
     * @param $id
     * @param mixed ...$args
     * @return mixed
     */
    function button($id = null, ...$args): mixed
    {
        static $keyboards = null;
        $keyboards ??= require_once base_path('utils/keyboards.php');
        $json = $args['json'] ?? true;
        $options = $args['options'] ?? [];
        $default = $args['default'] ?? null;
        unset($args['json'], $args['options'], $args['default']);

        if (isset($args['remove']) && $args['remove'] === true) {
            return Button::remove();
        } elseif (is_array($value = value(data_get($keyboards, $id, $default), ... $args))) {
            return Button::make($value, $options, $json);
        }

        return $default;
    }
}

if (!function_exists('array_first')) {
    /**
     * Getting first element of array
     * @param array $array
     * @return mixed
     * @example
     * array_first(some_function_returns_array());
     *
     */
    function array_first(array $array): mixed
    {
        return reset($array);
    }
}

if (!function_exists('array_last')) {
    /**
     * Getting last element of array
     * @param array $array
     * @return mixed
     * @example
     * array_last(some_function_returns_array());
     *
     */
    function array_last(array $array): mixed
    {
        return end($array);
    }
}

if (!function_exists('concat')) {
    function concat(...$vars): string
    {
        return implode($vars);
    }
}

if (!function_exists('str_splice')) {
    function str_splice($haystack, ?int $offset, ?int $length, ?string $replacement = ''): string
    {
        $search = substr($haystack, $offset, $length);

        return implode($replacement, explode($search, $haystack, 2));
    }
}

if (!function_exists('mb_str_splice')) {
    function mb_str_splice($haystack, ?int $offset, ?int $length, ?string $replacement = '', ?string $encoding = null): string
    {
        $search = mb_substr($haystack, $offset, $length);

        return implode($replacement, explode($search, $haystack, 2));
    }
}

if (!function_exists('array_map_recursive')) {
    /**
     * Recursive map into a array
     *
     * @param callable $callback
     * @param $array
     * @return array
     */
    function array_map_recursive(callable $callback, $array): array
    {
        $fn = function ($item) use (&$fn, &$callback) {
            return is_array($item) ? array_map($fn, $item) : call_user_func($callback, $item);
        };

        return array_map($fn, $array);
    }
}

if (!function_exists('dotty')) {
    function dotty(array $items = [], bool $parse = false): Dotty
    {
        return new Dotty($items, $parse);
    }
}

if (!function_exists('str_snake')) {
    /**
     * Convert string to snake_case
     *
     * @param string $string
     * @return string
     */
    function str_snake(string $string): string
    {
        if (!ctype_lower($string)) {
            return strtolower(preg_replace(
                '/(.)(?=[A-Z])/u', '$1_', preg_replace(
                    '/\s+/u', '', ucwords($string)
                )
            ));
        }

        return $string;
    }
}

if (!function_exists('array_sole')) {
    /**
     * @param array $array
     * @param callable $fn
     * @return mixed
     */
    function array_sole(array $array, callable $fn): mixed
    {
        foreach ($array as $index => $item) {
            if ($value = $fn($item, $index)) {
                return $value;
            }
        }

        return false;
    }
}