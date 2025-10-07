<?php

namespace LaravelAI\SmartAgent;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use LaravelAI\SmartAgent\AiAgentManager;

class AiAgentsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/ai-agents.php', 'ai-agents'
        );

        $this->app->singleton('ai-agents', function ($app) {
            return new AiAgentManager();
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/ai-agents.php' => config_path('ai-agents.php'),
        ], 'config');

        $this->registerCommands();
    }

    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallAiAgentsPackage::class,
            ]);
        }
    }
}
