<?php

declare(strict_types=1);

namespace App\Service;

final class CatalogApiClient
{
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(?string $baseUrl = null, int $timeoutSeconds = 5)
    {
        $configured = $baseUrl ?? getenv('CATALOG_API_URL');
        $this->baseUrl = rtrim(is_string($configured) && $configured !== '' ? $configured : 'http://catalog-api:8000', '/');
        $this->timeoutSeconds = max(1, $timeoutSeconds);
    }

    public function getPrice(string $ingredient, string $quantity, string $unit): array
    {
        if ($ingredient === '' || !preg_match('/^-?\d+(?:\.\d{1,3})?$/', $quantity) || (float) $quantity <= 0) {
            throw new \InvalidArgumentException('Catalog price lookup requires an ingredient and positive quantity');
        }
        return $this->request(
            'POST',
            '/prices',
            ['ingredient' => trim($ingredient), 'quantity' => (float) $quantity, 'unit' => $unit],
        );
    }

    public function getNutrition(string $ingredient, string $quantity = '100', string $unit = 'g'): array
    {
        if ($ingredient === '' || !preg_match('/^-?\d+(?:\.\d{1,3})?$/', $quantity) || (float) $quantity <= 0) {
            throw new \InvalidArgumentException('Nutrition lookup requires an ingredient and positive quantity');
        }
        return $this->request(
            'POST',
            '/nutrition',
            ['ingredient' => trim($ingredient), 'serving_quantity' => (float) $quantity, 'serving_unit' => $unit],
        );
    }

    private function request(string $method, string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($response === false) {
                throw new \RuntimeException('Catalog API connection failed: ' . ($error !== '' ? $error : 'unknown error'));
            }
        } else {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $payload,
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
                throw new \RuntimeException('Catalog API connection failed');
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Catalog API returned HTTP %d', $status));
        }
        try {
            $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Catalog API returned invalid JSON', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Catalog API returned an invalid response');
        }
        return $decoded;
    }
}
