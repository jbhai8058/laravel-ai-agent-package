<?php

namespace MuhammadJunaidRehmanSiddiqui\AiAgents\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallAiAgentsPackage extends Command
{
    protected $signature = 'ai-agents:install';
    protected $description = 'Install the AI Agents package and set up environment variables';

    public function handle()
    {
        $this->info('Installing AI Agents Package...');

        // Publish config
        $this->call('vendor:publish', [
            '--provider' => "MuhammadJunaidRehmanSiddiqui\\AiAgents\\AiAgentsServiceProvider",
            '--tag' => 'config'
        ]);

        // Copy .env.example to .env if it doesn't exist
        $envPath = base_path('.env');
        $envAiAgentsPath = base_path('.env.ai-agents');
        
        if (!File::exists($envAiAgentsPath)) {
            File::copy(__DIR__.'/../../.env.example', $envAiAgentsPath);
        }

        // Read current .env file
        $envContent = File::exists($envPath) ? File::get($envPath) : '';
        $envAiAgentsContent = File::get($envAiAgentsPath);

        // Extract variables from .env.ai-agents
        $requiredVars = [];
        foreach (explode("\n", $envAiAgentsContent) as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '=') !== false && substr($line, 0, 1) !== '#') {
                list($key) = explode('=', $line, 2);
                $requiredVars[] = $key;
            }
        }

        // Check if variables already exist
        $missingVars = [];
        foreach ($requiredVars as $var) {
            if (!preg_match("/^$var=/m", $envContent)) {
                $missingVars[] = $var;
            }
        }

        // Add missing variables to .env
        if (!empty($missingVars)) {
            $this->info('Adding required environment variables to .env file...');
            
            $newVars = [];
            foreach (explode("\n", $envAiAgentsContent) as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    list($key) = explode('=', $line, 2);
                    if (in_array($key, $missingVars)) {
                        $newVars[] = $line;
                    }
                }
            }
            
            if (!empty($newVars)) {
                File::append($envPath, "\n# AI Agents Package Configuration\n" . implode("\n", $newVars));
                $this->info('Environment variables added successfully!');
            }
        } else {
            $this->info('All required environment variables are already set.');
        }

        $this->info('AI Agents Package installed successfully!');
        $this->info('Please update your .env file with your actual API keys and configuration.');
    }
}
