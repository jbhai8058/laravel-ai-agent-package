<?php

namespace MuhammadJunaidRehmanSiddiqui\AiAgents\Drivers;

use Google\Cloud\AIPlatform\V1\PredictionServiceClient;
use Google\Cloud\AIPlatform\V1\Content;
use Google\Cloud\AIPlatform\V1\GenerateContentRequest;
use Google\Cloud\AIPlatform\V1\Content as GoogleContent;
use Google\Cloud\AIPlatform\V1\Part;
use Google\Protobuf\Value;
use MuhammadJunaidRehmanSiddiqui\AiAgents\Contracts\AiAgentInterface;

class GeminiDriver implements AiAgentInterface
{
    protected PredictionServiceClient $client;
    protected array $config;
    protected string $model;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->model = $config['default_model'] ?? 'gemini-pro';
        $this->client = new PredictionServiceClient([
            'credentials' => $config['credentials'] ?? null,
            'apiEndpoint' => $config['endpoint'] ?? 'us-central1-aiplatform.googleapis.com',
        ]);
    }

    public function sendPrompt(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        
        $content = (new GoogleContent())
            ->setRole('user')
            ->setParts([
                (new Part())
                    ->setText($prompt)
            ]);

        $formattedModel = $this->client->modelName($this->config['project_id'], 'us-central1', $model);
        
        $request = new GenerateContentRequest([
            'model' => $formattedModel,
            'contents' => [$content],
        ]);

        $response = $this->client->generateContent($request);
        
        return $response->getCandidates()[0]->getContent()->getParts()[0]->getText();
    }

    public function chat(array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $contents = [];

        foreach ($messages as $message) {
            $content = (new GoogleContent())
                ->setRole($message['role'])
                ->setParts([
                    (new Part())
                        ->setText($message['content'])
                ]);
            $contents[] = $content;
        }

        $formattedModel = $this->client->modelName($this->config['project_id'], 'us-central1', $model);
        
        $request = new GenerateContentRequest([
            'model' => $formattedModel,
            'contents' => $contents,
        ]);

        $response = $this->client->generateContent($request);
        
        return $response->getCandidates()[0]->getContent()->getParts()[0]->getText();
    }

    public function embed(string $text): array
    {
        $model = 'text-embedding-004';
        $formattedModel = $this->client->modelName($this->config['project_id'], 'us-central1', $model);
        
        $content = (new GoogleContent())
            ->setRole('user')
            ->setParts([
                (new Part())
                    ->setText($text)
            ]);

        $request = new GenerateContentRequest([
            'model' => $formattedModel,
            'contents' => [$content],
        ]);

        $response = $this->client->generateContent($request);
        
        // Convert the embedding to an array
        $embedding = $response->getCandidates()[0]->getContent()->getParts()[0]->getText();
        return json_decode($embedding, true);
    }
}
