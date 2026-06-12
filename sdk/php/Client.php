<?php
/**
 * WP Command Center PHP SDK
 *
 * Lightweight REST client for the WP Command Center API.
 *
 * @example
 * ```php
 * $client = new \WPCommandCenter\SDK\Client('https://example.com/wp-json/wp-command-center/v1', 'wpcc_abc123...');
 *
 * // Health check
 * $health = $client->health();
 *
 * // Run an operation directly
 * $result = $client->operationRun('content_seed', ['type' => 'post', 'count' => 3]);
 *
 * // Request → Approve → Execute workflow
 * $req  = $client->operationRequest('content_seed', ['type' => 'post', 'count' => 3]);
 * $client->operationApprove($req['request_id']);
 * $client->operationExecute($req['request_id']);
 *
 * // List operations
 * $ops = $client->listOperations();
 * ```
 */

namespace WPCommandCenter\SDK;

class Client
{
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
    }

    // ────────────────────────────────── HTTP helper ──────────────────────────────────

    /**
     * Send an HTTP request to the API.
     *
     * @param  string               $method GET|POST|PUT|DELETE
     * @param  string               $path   Endpoint path relative to base URL (e.g. '/health')
     * @param  array<string, mixed> $body   JSON body for POST requests
     * @return array<string, mixed>        Decoded response
     * @throws \RuntimeException On transport or HTTP error
     */
    public function request(string $method, string $path, array $body = []): array
    {
        $url     = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ]);

        if (in_array(strtoupper($method), ['POST', 'PUT'], true) && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("cURL error: {$curlError}");
        }

        $data = json_decode($response, true);

        if (null === $data && !empty($response)) {
            throw new \RuntimeException("Invalid JSON response (HTTP {$httpCode}): " . substr($response, 0, 200));
        }

        // Normalize: both WP REST API success data and WP_Error responses decode to arrays.
        // WP REST API returns errors as { code, message, data: { status } }.
        // Unsuccessful HTTP codes that aren't structured WP_Errors still throw.
        $data         = is_array($data) ? $data : ['_raw' => $response];
        $data['_http'] = $httpCode;

        return $data;
    }

    // ────────────────────────────────── Health ──────────────────────────────────

    /**
     * Health check — verify the API is reachable.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    // ────────────────────────────────── Operations ──────────────────────────────────

    /**
     * Get all registered operations (metadata only).
     *
     * @return array<string, mixed>
     */
    public function listOperations(): array
    {
        return $this->request('GET', '/operations');
    }

    /**
     * Get metadata for a specific operation by ID.
     *
     * @param  string               $operationId e.g. 'content_seed', 'plugin_manage'
     * @return array<string, mixed>
     */
    public function getOperation(string $operationId): array
    {
        return $this->request('GET', "/operations/{$operationId}");
    }

    /**
     * Run an operation directly (bypasses the request→approve→execute workflow).
     * Requires a full-access token.
     *
     * @param  string               $operationId e.g. 'content_seed'
     * @param  array<string, mixed> $payload     Operation-specific parameters
     * @return array<string, mixed>
     */
    public function operationRun(string $operationId, array $payload = []): array
    {
        return $this->request('POST', "/operations/{$operationId}/run", $payload);
    }

    // ────────────────── Operation Request / Approve / Execute workflow ──────────────────

    /**
     * Create an operation request (requires human approval before execution).
     *
     * @param  string               $operationId Operation identifier
     * @param  array<string, mixed> $payload     Operation parameters
     * @param  string               $sessionId   Optional agent session UUID
     * @param  string               $taskId      Optional agent task UUID
     * @return array<string, mixed>             Created request record with request_id
     */
    public function operationRequest(string $operationId, array $payload = [], string $sessionId = '', string $taskId = ''): array
    {
        $body = [
            'operation_id' => $operationId,
            'payload'      => $payload,
        ];

        if ($sessionId) {
            $body['session_id'] = $sessionId;
        }
        if ($taskId) {
            $body['task_id'] = $taskId;
        }

        return $this->request('POST', '/operations/requests', $body);
    }

    /**
     * List operation requests.
     *
     * @param  array<string, mixed> $filters Optional: status, operation_id, session_id, task_id, limit, offset
     * @return array<string, mixed>
     */
    public function listOperationRequests(array $filters = []): array
    {
        $query = '';
        if (!empty($filters)) {
            $query = '?' . http_build_query($filters);
        }
        return $this->request('GET', "/operations/requests{$query}");
    }

    /**
     * Get a specific operation request by ID.
     *
     * @param  string               $requestId
     * @return array<string, mixed>
     */
    public function getOperationRequest(string $requestId): array
    {
        return $this->request('GET', "/operations/requests/{$requestId}");
    }

    /**
     * Approve a pending operation request.
     *
     * @param  string               $requestId
     * @return array<string, mixed>
     */
    public function operationApprove(string $requestId): array
    {
        return $this->request('POST', "/operations/requests/{$requestId}/approve");
    }

    /**
     * Execute an approved operation request.
     *
     * @param  string               $requestId
     * @return array<string, mixed>
     */
    public function operationExecute(string $requestId): array
    {
        return $this->request('POST', "/operations/requests/{$requestId}/execute");
    }

    /**
     * Reject a pending operation request.
     *
     * @param  string               $requestId
     * @return array<string, mixed>
     */
    public function operationReject(string $requestId): array
    {
        return $this->request('POST', "/operations/requests/{$requestId}/reject");
    }

    /**
     * Queue an approved operation request for deferred execution.
     *
     * @param  string               $requestId
     * @param  int                  $priority  Default 10
     * @return array<string, mixed>
     */
    public function operationQueue(string $requestId, int $priority = 10): array
    {
        return $this->request('POST', "/operations/requests/{$requestId}/queue?priority={$priority}");
    }

    // ────────────────────────────────── Queue ──────────────────────────────────

    /**
     * List queued operations.
     *
     * @param  array<string, mixed> $filters Optional: status, operation_id, request_id, limit, offset
     * @return array<string, mixed>
     */
    public function listQueue(array $filters = []): array
    {
        $query = '';
        if (!empty($filters)) {
            $query = '?' . http_build_query($filters);
        }
        return $this->request('GET', "/operations/queue{$query}");
    }

    /**
     * List operation execution results.
     *
     * @param  array<string, mixed> $filters Optional: operation_id, queue_id, request_id, status, limit, offset
     * @return array<string, mixed>
     */
    public function listResults(array $filters = []): array
    {
        $query = '';
        if (!empty($filters)) {
            $query = '?' . http_build_query($filters);
        }
        return $this->request('GET', "/operations/results{$query}");
    }

    // ────────────────────────────────── Site Intelligence ──────────────────────────────────

    /**
     * Full site scan: WordPress, PHP, theme, plugins, cache, and server info.
     *
     * @return array<string, mixed>
     */
    public function siteIntelligence(): array
    {
        return $this->request('GET', '/site-intelligence');
    }

    // ────────────────────────────────── Diagnostics ──────────────────────────────────

    /**
     * Run diagnostics: performance, security, or woocommerce.
     *
     * @param  string               $type performance|security|woocommerce
     * @return array<string, mixed>
     */
    public function diagnostics(string $type = 'performance'): array
    {
        return $this->request('GET', "/diagnostics?type={$type}");
    }

    /**
     * Tail the debug log.
     *
     * @param  int                  $lines Number of lines (default 100)
     * @return array<string, mixed>
     */
    public function debugLog(int $lines = 100): array
    {
        return $this->request('GET', "/diagnostics/debug-log?lines={$lines}");
    }

    // ────────────────────────────────── Files ──────────────────────────────────

    /**
     * List files in a directory under wp-content/.
     *
     * @param  string               $path Relative to wp-content/ (themes, plugins, mu-plugins)
     * @return array<string, mixed>
     */
    public function listFiles(string $path = ''): array
    {
        $query = $path ? '?path=' . urlencode($path) : '';
        return $this->request('GET', "/files{$query}");
    }

    /**
     * Read a file's contents.
     *
     * @param  string               $path Relative to wp-content/
     * @return array<string, mixed>
     */
    public function fileContent(string $path): array
    {
        return $this->request('GET', '/files/content?path=' . urlencode($path));
    }

    // ────────────────────────────────── Patches ──────────────────────────────────

    /**
     * Create a patch proposal.
     *
     * @param  array<int, array{path: string, modified: string}> $files
     * @param  string               $explanation
     * @param  string               $riskLevel   low|medium|high|critical
     * @return array<string, mixed>
     */
    public function createPatch(array $files, string $explanation, string $riskLevel = 'low'): array
    {
        return $this->request('POST', '/patches', [
            'files'       => $files,
            'explanation' => $explanation,
            'risk_level'  => $riskLevel,
        ]);
    }

    /**
     * Approve a pending patch.
     *
     * @param  string               $patchId
     * @return array<string, mixed>
     */
    public function approvePatch(string $patchId): array
    {
        return $this->request('POST', "/patches/{$patchId}/approve");
    }

    /**
     * Apply an approved patch.
     *
     * @param  string               $patchId
     * @return array<string, mixed>
     */
    public function applyPatch(string $patchId): array
    {
        return $this->request('POST', "/patches/{$patchId}/apply");
    }

    /**
     * Roll back an applied patch.
     *
     * @param  string               $patchId
     * @return array<string, mixed>
     */
    public function rollbackPatch(string $patchId): array
    {
        return $this->request('POST', "/patches/{$patchId}/rollback");
    }

    // ────────────────────────────────── Agent ──────────────────────────────────

    /**
     * Get the agent manifest.
     *
     * @return array<string, mixed>
     */
    public function agentManifest(): array
    {
        return $this->request('GET', '/agent/manifest');
    }

    /**
     * Get agent runtime context.
     *
     * @param  string               $sessionId Optional session UUID to scope context
     * @return array<string, mixed>
     */
    public function agentContext(string $sessionId = ''): array
    {
        $query = $sessionId ? '?session_id=' . urlencode($sessionId) : '';
        return $this->request('GET', "/agent/context{$query}");
    }
}
