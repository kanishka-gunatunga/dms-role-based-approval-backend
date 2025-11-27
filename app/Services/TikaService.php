<?php

namespace App\Services;

use GuzzleHttp\Client;

class TikaService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => env('TIKA_SERVER_URL', 'http://localhost:9998/'),
            'timeout'  => 120,
        ]);
    }

    /**
     * Extract full text
     */
    public function extractText($filePath)
    {
        $response = $this->client->request('PUT', 'tika', [
            'body' => fopen($filePath, 'r')
        ]);

        return (string) $response->getBody();
    }

    /**
     * Extract text page by page (works for PDFs)
     * If not supported, return entire content as single page.
     */
  public function extractTextPages($filePath)
{
    try {
        // Use rmeta/text to preserve page boundaries
        $response = $this->client->request('PUT', 'rmeta/text', [
            'headers' => ['Accept' => 'application/json'],
            'body' => fopen($filePath, 'r')
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $pages = [];

        if (is_array($data)) {
            foreach ($data as $pageData) {
                if (isset($pageData['X-TIKA:content'])) {
                    $text = trim($pageData['X-TIKA:content']);
                    if ($text !== '') {
                        $pages[] = $text;
                    }
                }
            }
        }

        // Determine file extension
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $resultPages = [];

        if ($ext === 'pdf') {
            // PDFs: page-by-page
            if (empty($pages)) {
                // fallback: full PDF as single page
                $pages[] = $this->extractText($filePath);
            }
            foreach ($pages as $i => $text) {
                $resultPages[(string)($i + 1)] = $text;
            }
        } else {
            // Other formats: return as single page
            $content = empty($pages) ? $this->extractText($filePath) : implode("\n", $pages);
            $resultPages['1'] = $content;
        }

        return $resultPages;

    } catch (\Exception $e) {
        // fallback: full content as single page
        return ['1' => $this->extractText($filePath)];
    }
}

    /**
     * Extract metadata
     */
    public function extractMetadata($filePath)
    {
        $response = $this->client->request('PUT', 'meta', [
            'body' => fopen($filePath, 'r')
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
