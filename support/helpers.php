<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Container;
use support\Request;
use support\Response;
use support\Translation;
use support\view\Blade;
use support\view\Raw;
use support\view\ThinkPHP;
use support\view\Twig;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Webman\App;
use Webman\Config;
use Webman\Route;
use Workerman\Protocols\Http\Session;
use Workerman\Worker;

// Project base path
define('BASE_PATH', dirname(__DIR__));

/**
 * return the program execute directory
 * @param string $path
 * @return string
 */
function run_path(string $path = ''): string
{
    static $runPath = '';
    if (!$runPath) {
        $runPath = is_phar() ? dirname(Phar::running(false)) : BASE_PATH;
    }
    return path_combine($runPath, $path);
}

/**
 * if the param $path equal false,will return this program current execute directory
 * @param string|false $path
 * @return string
 */
function base_path($path = ''): string
{
    if (false === $path) {
        return run_path();
    }
    return path_combine(BASE_PATH, $path);
}

/**
 * App path
 * @param string $path
 * @return string
 */
function app_path(string $path = ''): string
{
    return path_combine(BASE_PATH . DIRECTORY_SEPARATOR . 'app', $path);
}

/**
 * Public path
 * @param string $path
 * @param string|null $plugin
 * @return string
 */
function public_path(string $path = '', string $plugin = null): string
{
    static $publicPaths = [];
    $plugin = $plugin ?? '';
    if (isset($publicPaths[$plugin])) {
        $publicPath = $publicPaths[$plugin];
    } else {
        $prefix = $plugin ? "plugin.$plugin." : '';
        $pathPrefix = $plugin ? 'plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR : '';
        $publicPath = \config("{$prefix}app.public_path", run_path("{$pathPrefix}public"));
        if (count($publicPaths) > 32) {
            $publicPaths = [];
        }
        $publicPaths[$plugin] = $publicPath;
    }
    return $path === '' ? $publicPath : path_combine($publicPath, $path);
}

/**
 * Config path
 * @param string $path
 * @return string
 */
function config_path(string $path = ''): string
{
    return path_combine(BASE_PATH . DIRECTORY_SEPARATOR . 'config', $path);
}

/**
 * Runtime path
 * @param string $path
 * @return string
 */
function runtime_path(string $path = ''): string
{
    static $runtimePath = '';
    if (!$runtimePath) {
        $runtimePath = \config('app.runtime_path') ?: run_path('runtime');
    }
    return path_combine($runtimePath, $path);
}

/**
 * Generate paths based on given information
 * @param string $front
 * @param string $back
 * @return string
 */
function path_combine(string $front, string $back): string
{
    return $front . ($back ? (DIRECTORY_SEPARATOR . ltrim($back, DIRECTORY_SEPARATOR)) : $back);
}

/**
 * Response
 * @param int $status
 * @param array $headers
 * @param string $body
 * @return Response
 */
function response(string $body = '', int $status = 200, array $headers = []): Response
{
    return new Response($status, $headers, $body);
}

/**
 * Json response
 * @param $data
 * @param int $options
 * @return Response
 */
function json($data, int $options = JSON_UNESCAPED_UNICODE): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, $options));
}

/**
 * Xml response
 * @param $xml
 * @return Response
 */
function xml($xml): Response
{
    if ($xml instanceof SimpleXMLElement) {
        $xml = $xml->asXML();
    }
    return new Response(200, ['Content-Type' => 'text/xml'], $xml);
}

/**
 * Jsonp response
 * @param $data
 * @param string $callbackName
 * @return Response
 */
function jsonp($data, string $callbackName = 'callback'): Response
{
    if (!is_scalar($data) && null !== $data) {
        $data = json_encode($data);
    }
    return new Response(200, [], "$callbackName($data)");
}

/**
 * Redirect response
 * @param string $location
 * @param int $status
 * @param array $headers
 * @return Response
 */
function redirect(string $location, int $status = 302, array $headers = []): Response
{
    $response = new Response($status, ['Location' => $location]);
    if (!empty($headers)) {
        $response->withHeaders($headers);
    }
    return $response;
}

/**
 * View response
 * @param mixed $template
 * @param array $vars
 * @param string|null $app
 * @param string|null $plugin
 * @return Response
 */
function view($template = null, array $vars = [], string $app = null, string $plugin = null): Response
{
    [$template, $vars, $app, $plugin] = template_inputs($template, $vars, $app, $plugin);
    $handler = \config($plugin ? "plugin.$plugin.view.handler" : 'view.handler');
    return new Response(200, [], $handler::render($template, $vars, $app, $plugin));
}

/**
 * Raw view response
 * @param mixed $template
 * @param array $vars
 * @param string|null $app
 * @param string|null $plugin
 * @return Response
 * @throws Throwable
 */
function raw_view($template = null, array $vars = [], string $app = null, string $plugin = null): Response
{
    return new Response(200, [], Raw::render(...template_inputs($template, $vars, $app, $plugin)));
}

/**
 * Blade view response
 * @param mixed $template
 * @param array $vars
 * @param string|null $app
 * @param string|null $plugin
 * @return Response
 */
function blade_view($template = null, array $vars = [], string $app = null, string $plugin = null): Response
{
    return new Response(200, [], Blade::render(...template_inputs($template, $vars, $app, $plugin)));
}

/**
 * Think view response
 * @param mixed $template
 * @param array $vars
 * @param string|null $app
 * @param string|null $plugin
 * @return Response
 */
function think_view($template = null, array $vars = [], string $app = null, string $plugin = null): Response
{
    return new Response(200, [], ThinkPHP::render(...template_inputs($template, $vars, $app, $plugin)));
}

/**
 * Twig view response
 * @param mixed $template
 * @param array $vars
 * @param string|null $app
 * @param string|null $plugin
 * @return Response
 */
function twig_view($template = null, array $vars = [], string $app = null, string $plugin = null): Response
{
    return new Response(200, [], Twig::render(...template_inputs($template, $vars, $app, $plugin)));
}

/**
 * Get request
 * @return \Webman\Http\Request|Request|null
 */
function request()
{
    return App::request();
}

/**
 * Get config
 * @param string|null $key
 * @param $default
 * @return array|mixed|null
 */
function config(string $key = null, $default = null)
{
    return Config::get($key, $default);
}

/**
 * Create url
 * @param string $name
 * @param ...$parameters
 * @return string
 */
function route(string $name, ...$parameters): string
{
    $route = Route::getByName($name);
    if (!$route) {
        return '';
    }

    if (!$parameters) {
        return $route->url();
    }

    if (is_array(current($parameters))) {
        $parameters = current($parameters);
    }

    return $route->url($parameters);
}

/**
 * Session
 * @param mixed $key
 * @param mixed $default
 * @return mixed|bool|Session
 * @throws Exception
 */
function session($key = null, $default = null)
{
    $session = \request()->session();
    if (null === $key) {
        return $session;
    }
    if (is_array($key)) {
        $session->put($key);
        return null;
    }
    if (strpos($key, '.')) {
        $keyArray = explode('.', $key);
        $value = $session->all();
        foreach ($keyArray as $index) {
            if (!isset($value[$index])) {
                return $default;
            }
            $value = $value[$index];
        }
        return $value;
    }
    return $session->get($key, $default);
}

/**
 * Translation
 * @param string $id
 * @param array $parameters
 * @param string|null $domain
 * @param string|null $locale
 * @return string
 */
function trans(string $id, array $parameters = [], string $domain = null, string $locale = null): string
{
    $res = Translation::trans($id, $parameters, $domain, $locale);
    return $res === '' ? $id : $res;
}

/**
 * Locale
 * @param string|null $locale
 * @return string
 */
function locale(string $locale = null): string
{
    if (!$locale) {
        return Translation::getLocale();
    }
    Translation::setLocale($locale);
    return $locale;
}

/**
 * 404 not found
 * @return Response
 */
function not_found(): Response
{
    return new Response(404, [], file_get_contents(public_path() . '/404.html'));
}

/**
 * Copy dir
 * @param string $source
 * @param string $dest
 * @param bool $overwrite
 * @return void
 */
function copy_dir(string $source, string $dest, bool $overwrite = false)
{
    if (is_dir($source)) {
        if (!is_dir($dest)) {
            mkdir($dest);
        }
        $files = scandir($source);
        foreach ($files as $file) {
            if ($file !== "." && $file !== "..") {
                copy_dir("$source/$file", "$dest/$file", $overwrite);
            }
        }
    } else if (file_exists($source) && ($overwrite || !file_exists($dest))) {
        copy($source, $dest);
    }
}

/**
 * Remove dir
 * @param string $dir
 * @return bool
 */
function remove_dir(string $dir): bool
{
    if (is_link($dir) || is_file($dir)) {
        return unlink($dir);
    }
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file") && !is_link($dir)) ? remove_dir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

/**
 * Bind worker
 * @param $worker
 * @param $class
 */
function worker_bind($worker, $class)
{
    $callbackMap = [
        'onConnect',
        'onMessage',
        'onClose',
        'onError',
        'onBufferFull',
        'onBufferDrain',
        'onWorkerStop',
        'onWebSocketConnect',
        'onWorkerReload'
    ];
    foreach ($callbackMap as $name) {
        if (method_exists($class, $name)) {
            $worker->$name = [$class, $name];
        }
    }
    if (method_exists($class, 'onWorkerStart')) {
        call_user_func([$class, 'onWorkerStart'], $worker);
    }
}

/**
 * Start worker
 * @param $processName
 * @param $config
 * @return void
 */
function worker_start($processName, $config)
{
    if (isset($config['enable']) && !$config['enable']) {
        return;
    }
    // feat：custom worker class [default: Workerman\Worker]
    $class = is_a($class = $config['workerClass'] ?? '', Worker::class, true) ? $class : Worker::class;
    $worker = new $class($config['listen'] ?? null, $config['context'] ?? []);
    $properties = [
        'count',
        'user',
        'group',
        'reloadable',
        'reusePort',
        'transport',
        'protocol',
        'eventLoop',
    ];
    $worker->name = $processName;
    foreach ($properties as $property) {
        if (isset($config[$property])) {
            $worker->$property = $config[$property];
        }
    }

    $worker->onWorkerStart = function ($worker) use ($config) {
        require_once base_path('/support/bootstrap.php');
        if (isset($config['handler'])) {
            if (!class_exists($config['handler'])) {
                return;
            }

            $instance = Container::make($config['handler'], $config['constructor'] ?? []);
            worker_bind($worker, $instance);
        }
    };
}

/**
 * Get realpath
 * @param string $filePath
 * @return string
 */
function get_realpath(string $filePath): string
{
    if (strpos($filePath, 'phar://') === 0) {
        return $filePath;
    } else {
        return realpath($filePath);
    }
}

/**
 * Is phar
 * @return bool
 */
function is_phar(): bool
{
    return class_exists(Phar::class, false) && Phar::running();
}

/**
 * Get template vars
 * @param mixed $template
 * @param array $vars
 * @param string|null $app
 * @param string|null $plugin
 * @return array
 */
function template_inputs($template, array $vars, ?string $app, ?string $plugin): array
{
    $request = \request();
    $plugin = $plugin === null ? ($request->plugin ?? '') : $plugin;
    if (is_array($template)) {
        $vars = $template;
        $template = null;
    }
    if ($template === null && $controller = $request->controller) {
        $controllerSuffix = config($plugin ? "plugin.$plugin.app.controller_suffix" : "app.controller_suffix", '');
        $controllerName = $controllerSuffix !== '' ? substr($controller, 0, -strlen($controllerSuffix)) : $controller;
        $path = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr(strrchr($controllerName, '\\'), 1)));
        $actionFileBaseName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $request->action));
        $template = "$path/$actionFileBaseName";
    }
    return [$template, $vars, $app, $plugin];
}

/**
 * Get cpu count
 * @return int
 */
function cpu_count(): int
{
    // Windows does not support the number of processes setting.
    if (DIRECTORY_SEPARATOR === '\\') {
        return 1;
    }
    $count = 4;
    if (is_callable('shell_exec')) {
        if (strtolower(PHP_OS) === 'darwin') {
            $count = (int)shell_exec('sysctl -n machdep.cpu.core_count');
        } else {
            try {
                $count = (int)shell_exec('nproc');
            } catch (\Throwable $ex) {
                // Do nothing
            }
        }
    }
    return $count > 0 ? $count : 4;
}


/**
 * Get request parameters, if no parameter name is passed, an array of all values is returned, default values is supported
 * @param string|null $param param's name
 * @param mixed|null $default default value
 * @return mixed|null
 */
function input(string $param = null, $default = null)
{
    return is_null($param) ? request()->all() : request()->input($param, $default);
}

/**
 * 验证 Lua 脚本参数
 *
 * 用于在调用 RedisLuaScripts 的 atomicBet/atomicSettle/atomicCancel 前验证参数
 *
 * @param array $params 要验证的参数数组
 * @param array $rules 验证规则
 * @param string $operation 操作名称（用于错误消息）
 * @throws InvalidArgumentException 参数验证失败时抛出
 *
 * 规则格式：
 * [
 *     'field_name' => ['required', 'numeric', 'min:0'],
 *     'field_name2' => ['string'],
 * ]
 *
 * 支持的规则：
 * - required: 必需字段，不能为 null
 * - numeric: 必须是数字（int/float/numeric string）
 * - integer: 必须是整数
 * - string: 必须是字符串
 * - min:n: 最小值（仅用于数字）
 * - max:n: 最大值（仅用于数字）
 *
 * 示例：
 * validateLuaScriptParams($data, [
 *     'order_no' => ['required', 'string'],
 *     'amount' => ['required', 'numeric', 'min:0'],
 *     'platform_id' => ['required', 'integer'],
 *     'game_code' => ['string'],  // 可选字段
 * ], 'atomicBet');
 */
function validateLuaScriptParams(array $params, array $rules, string $operation = 'Lua script'): void
{
    foreach ($rules as $field => $fieldRules) {
        $fieldRules = is_array($fieldRules) ? $fieldRules : [$fieldRules];
        $value = $params[$field] ?? null;

        // 检查 required
        if (in_array('required', $fieldRules)) {
            if ($value === null || $value === '') {
                throw new InvalidArgumentException(
                    sprintf('[%s] 参数验证失败: %s 是必需的', $operation, $field)
                );
            }
        }

        // 如果值为空且不是 required，跳过其他验证
        if ($value === null || $value === '') {
            continue;
        }

        // 检查 string
        if (in_array('string', $fieldRules) && !is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('[%s] 参数验证失败: %s 必须是字符串，实际类型: %s', $operation, $field, gettype($value))
            );
        }

        // 检查 numeric
        if (in_array('numeric', $fieldRules) && !is_numeric($value)) {
            throw new InvalidArgumentException(
                sprintf('[%s] 参数验证失败: %s 必须是数字，实际值: %s', $operation, $field, var_export($value, true))
            );
        }

        // 检查 integer
        if (in_array('integer', $fieldRules) && !is_int($value) && !(is_numeric($value) && (int)$value == $value)) {
            throw new InvalidArgumentException(
                sprintf('[%s] 参数验证失败: %s 必须是整数，实际值: %s', $operation, $field, var_export($value, true))
            );
        }

        // 检查 min
        foreach ($fieldRules as $rule) {
            if (strpos($rule, 'min:') === 0) {
                $min = (float)substr($rule, 4);
                if (is_numeric($value) && (float)$value < $min) {
                    throw new InvalidArgumentException(
                        sprintf('[%s] 参数验证失败: %s 必须 >= %s，实际值: %s', $operation, $field, $min, $value)
                    );
                }
            }
        }

        // 检查 max
        foreach ($fieldRules as $rule) {
            if (strpos($rule, 'max:') === 0) {
                $max = (float)substr($rule, 4);
                if (is_numeric($value) && (float)$value > $max) {
                    throw new InvalidArgumentException(
                        sprintf('[%s] 参数验证失败: %s 必须 <= %s，实际值: %s', $operation, $field, $max, $value)
                    );
                }
            }
        }
    }
}
