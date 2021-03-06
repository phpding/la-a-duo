<?php

namespace Ichynul\LaADuo;

use Encore\Admin\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class LaADuoServiceProvider extends ServiceProvider
{
    /**
     * @var array
     */
    protected $commands = [
        Console\Installer::class,
        Console\Router::class,
        Console\Builder::class,
        Console\Seeder::class,
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'lad.auth' => Middleware\Authenticate::class,
        'lad.prefix' => Middleware\Prefix::class,
        'lad.guards' => Middleware\AdminGuards::class,
    ];

    protected $middlewareGroups = [
        'lad.admin' => [
            'lad.auth',
            'lad.guards',
            'admin.pjax',
            'admin.log',
            'admin.bootstrap',
            'admin.permission',
        ],
    ];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerRouteMiddleware();
    }

    /**
     * {@inheritdoc}
     */
    public function boot(LaADuoExt $extension)
    {
        if (!LaADuoExt::boot()) {
            return;
        }

        if ($views = $extension->views()) {
            $this->loadViewsFrom($views, 'la-a-duo');
        }

        if ($this->app->runningInConsole() && $assets = $extension->assets()) {
            $this->publishes(
                [$assets => public_path('vendor/laravel-admin-ext/la-a-duo')],
                'la-a-duo'
            );
        }

        $this->app->booted(function () {
            LaADuoExt::routes(__DIR__ . '/../routes/web.php');
        });

        $this->mapWebRoutes();

        $this->commands($this->commands);

        if (!$this->app->runningInConsole() && LaADuoExt::config('apart', true)) {
            Admin::booted(function () {
                if (!Auth::guard('admin')->guest() && !LaADuoExt::$bootPrefix) { //current is base admin

                    $prefixes = LaADuoExt::config('prefixes', []);

                    foreach ($prefixes as $prefix) {

                        Session::remove(Auth::guard($prefix)->getName()); // delete session state of other prefixes guards.
                    }
                }
            });
        }
    }

    /**
     * Define routes for the application.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        $prefixes = LaADuoExt::config('prefixes', []);

        $route = config('admin.route', []);

        $authController = config('admin.auth.controller', '');

        $basePrefix = array_get($route, 'prefix', 'admin');

        $baseMiddleware = array_get($route, 'middleware', []);

        LaADuoExt::$basePrefix = $basePrefix;

        foreach ($prefixes as $prefix) {

            if ($prefix == $basePrefix) {
                continue;
            }

            $this->setGurd($prefix);
            
            if (!preg_match('/^\w+$/', $prefix)) {
                continue;
            }

            $directory = app_path(ucfirst($prefix));

            $middleware = $baseMiddleware;

            $thisMiddleware = config("{$prefix}.route.middleware", []);

            if (!empty($thisMiddleware)) {

                $middleware = $thisMiddleware;
            }

            $namespace = LaADuoExt::getNamespace($prefix);

            $middleware = array_diff($middleware, ['admin', 'web']);

            array_unshift($middleware, 'lad.admin');

            array_unshift($middleware, "lad.prefix:{$prefix}");

            array_unshift($middleware, 'web');

            config([
                'admin.route' => [
                    'prefix' => $prefix,
                    'namespace' => $namespace,
                    'middleware' => $middleware,
                ],
                'admin.auth.controller' => config("{$prefix}.auth.controller", LaADuoExt::getDefaultAuthController($prefix)),
            ]);

            if (!is_dir($directory)) {
                continue;
            }

            $routesPath = $directory . DIRECTORY_SEPARATOR . "routes.php";

            if (!file_exists($routesPath)) {
                continue;
            }

            $this->loadRoutesFrom($routesPath);

            $extRoutesFile = $directory . DIRECTORY_SEPARATOR . "extroutes.php";

            if (file_exists($extRoutesFile)) {

                $this->loadRoutesFrom($extRoutesFile);
            }
        }

        config([
            'admin.route' => $route, 'admin.auth.controller' => $authController,
        ]);
    }

    /**
     * Add guard into /config/auth.php
     *
     * @param [type] $prefix
     * @return void
     */
    protected function setGurd($prefix)
    {
        config(['auth.guards.' . $prefix => [
            'driver' => 'session',
            'provider' => 'admin',
        ]]);
    }

    /**
     * Register the route middleware.
     *
     * @return void
     */
    protected function registerRouteMiddleware()
    {
        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }

        // register middleware group.
        foreach ($this->middlewareGroups as $key => $middleware) {
            app('router')->middlewareGroup($key, $middleware);
        }
    }
}
