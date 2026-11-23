<?php

declare(strict_types=1);

namespace App\Service;

final class LlmClient
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $baseUrl = null, int $timeoutSeconds = 20)
    {
        $envKey = getenv('LLM_API_KEY');
        $envModel = getenv('LLM_MODEL');
        $envUrl = getenv('LLM_BASE_URL');
        $this->apiKey = trim($apiKey ?? (is_string($envKey) ? $envKey : ''));
        $this->model = trim($model ?? (is_string($envModel) ? $envModel : ''));
        $this->baseUrl = rtrim(trim($baseUrl ?? (is_string($envUrl) ? $envUrl : '')), '/');
        $this->timeoutSeconds = max(1, $timeoutSeconds);
    }

    public function isDryRun(): bool
    {
        return $this->apiKey === '';
    }

    public function generateStructuredJson(string $systemPrompt, array $input, array $mockResponse): array
    {
        if ($this->isDryRun()) {
            return $mockResponse;
        }
        if ($this->baseUrl === '' || $this->model === '') {
            throw new \RuntimeException('LLM_BASE_URL and LLM_MODEL are required when LLM_API_KEY is configured');
        }
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            ],
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
        ];
        $response = $this->postJson($this->baseUrl . '/chat/completions', $payload);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('LLM returned no structured JSON content');
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('LLM returned invalid JSON', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('LLM JSON root must be an object');
        }
        return $decoded;
    }

    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($response === false) {
                throw new \RuntimeException('LLM connection failed: ' . ($error !== '' ? $error : 'unknown error'));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer " . $this->apiKey . "\r\n",
                    'content' => $body,
                    'timeout' => $this->timeoutSeconds,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            $status = 200;
            if (isset($http_response_header) && isset($http_response_header[0])
                && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)
            ) {
                $status = (int) $matches[1];
            }
            if ($response === false) {
                throw new \RuntimeException('LLM connection failed');
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('LLM returned HTTP %d', $status));
        }
        try {
            $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('LLM provider returned invalid JSON', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('LLM provider returned an invalid response');
        }
        return $decoded;
    }
}
