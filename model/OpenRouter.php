<?php
require_once __DIR__ . '/../load_env.php';
loadEnv(__DIR__ . '/../.env');

class OpenRouterClient
{
    private $apiKey;
    private $baseUrl;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?: getenv('openRouterApiKey');
        $this->baseUrl = 'https://openrouter.ai/api/v1/chat/completions';
    }

    public function createChatCompletion(string $model, array $messages, array $options = []): array
    {
        if (!$this->apiKey) {
            throw new RuntimeException('OpenRouter API key not configured.');
        }

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.4
        ], $options);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('OpenRouter request failed: ' . $error);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $message = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Unknown error';
            throw new RuntimeException('OpenRouter error: ' . $message);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid response from OpenRouter.');
        }

        return $decoded;
    }
}
