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

        // Add required environment variables to .env
        $envPath = base_path('.env');
        
        if (!file_exists($envPath)) {
            $this->error('.env file not found!');
            return;
        }

        $envContent = file_get_contents($envPath);
        $requiredVars = [
            'OPENAI_API_KEY=',
            'GOOGLE_CLOUD_PROJECT_ID=',
            'GOOGLE_APPLICATION_CREDENTIALS=',
            'AI_AGENT_DRIVER=openai',
            'AI_AGENT_MODEL=gpt-4',
        ];

        $missingVars = [];
        foreach ($requiredVars as $var) {
            $varName = explode('=', $var)[0];
            if (strpos($envContent, $varName) === false) {
                $missingVars[] = $var;
            }
        }

        if (!empty($missingVars)) {
            file_put_contents($envPath, PHP_EOL . "# AI Agents Package Configuration" . PHP_EOL . implode(PHP_EOL, $missingVars) . PHP_EOL, FILE_APPEND);
            $this->info('Added required environment variables to .env file.');
        } else {
            $this->info('All required environment variables already exist in .env file.');
        }

        $this->info('AI Agents Package installed successfully!');
    }
}
