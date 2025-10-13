<?php
namespace LaravelAI\SmartAgent\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LaravelAI\SmartAgent\Contracts\AiAgentInterface;

class QueryExecutionService
{
    protected $aiAgent;

    public function __construct(AiAgentInterface $aiAgent)
    {
        $this->aiAgent = $aiAgent;
    }

    public function executeSafeQuery(string $query, array $bindings = [])
    {
        $query = trim($query);
        // Only allow SELECT queries for safety
        if (! Str::startsWith(strtoupper($query), 'SELECT')) {
            throw new InvalidArgumentException('Only SELECT queries are allowed for safety reasons');
        }
        try {
            return DB::select($query, $bindings);
        } catch (\Exception $e) {
            throw new \RuntimeException("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Validate any SQL query using AI for comprehensive validation
     *
     * @param string $query The SQL query to validate
     *
     * @return array [
     *     'valid' => bool,
     *     'message' => string,
     *     'type' => string
     * ]
     */
    public function validateQuery(string $query): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [
                'valid'   => false,
                'message' => 'Query cannot be empty',
                'type'    => 'error'
            ];
        }
        // Prepare a comprehensive prompt for the AI agent
        $prompt = "Analyze this SQL query and provide a validation response in JSON format. " . "The query is: \n\n" . $query . "\n\n" . "Provide a JSON response with these fields:\n" . "{\n" . "  'valid': boolean,           // Whether the query is valid SQL\n" . "  'type': 'SELECT|INSERT|UPDATE|DELETE|OTHER',  // Query type\n" . "  'is_destructive': boolean,  // If the query modifies data\n" . "  'security_risk': 'none|low|medium|high',  // Potential security risk level\n" . "  'message': 'Detailed validation message',
" . "  'suggestions': [           // Optional suggestions for improvement
" . "    'suggestion 1',
" . "    'suggestion 2'
" . "  ]\n" . "}";
        try {
            $response = $this->aiAgent->sendPrompt($prompt);
            $result   = json_decode($response, true);
            // Basic validation of the AI response
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from AI agent');
            }
            // Ensure required fields exist
            $result['valid']          = $result['valid'] ?? false;
            $result['type']           = strtoupper($result['type'] ?? 'OTHER');
            $result['is_destructive'] = $result['is_destructive'] ?? false;
            $result['security_risk']  = strtolower($result['security_risk'] ?? 'none');
            $result['message']        = $result['message'] ?? 'Validation completed';
            $result['suggestions']    = $result['suggestions'] ?? [];

            return $result;
        } catch (\Exception $e) {
            return [
                'valid'          => false,
                'type'           => 'ERROR',
                'is_destructive' => true,
                'security_risk'  => 'high',
                'message'        => 'Failed to validate query: ' . $e->getMessage(),
                'suggestions'    => ['Check the query syntax and try again']
            ];
        }
    }
}
