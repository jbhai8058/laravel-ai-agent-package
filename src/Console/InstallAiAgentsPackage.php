<?php

namespace MuhammadJunaidRehmanSiddiqui\AiAgents\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallAiAgentsPackage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai-agents:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the AI Agents package';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->info('Installing AI Agents Package...');
        
        // Publish config
        $this->call('vendor:publish', [
            '--provider' => "MuhammadJunaidRehmanSiddiqui\\AiAgents\\AiAgentsServiceProvider",
            '--tag' => 'config'
        ]);

        $this->info('AI Agents Package installed successfully!');
    }
}
