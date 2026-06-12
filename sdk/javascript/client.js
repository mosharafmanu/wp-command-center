/**
 * WP Command Center JavaScript SDK
 *
 * Lightweight REST client for the WP Command Center API.
 *
 * @example
 * ```js
 * import { WPCCClient } from './client.js';
 *
 * const client = new WPCCClient('https://example.com/wp-json/wp-command-center/v1', 'wpcc_abc123...');
 *
 * // Health check
 * const health = await client.health();
 *
 * // Run an operation directly
 * const result = await client.operationRun('content_seed', { type: 'post', count: 3 });
 *
 * // Request → Approve → Execute workflow
 * const req = await client.operationRequest('content_seed', { type: 'post', count: 3 });
 * await client.operationApprove(req.request_id);
 * await client.operationExecute(req.request_id);
 *
 * // List operations
 * const ops = await client.listOperations();
 * ```
 */

export class WPCCClient {
  /**
   * @param {string} baseUrl - REST API base URL (e.g. https://example.com/wp-json/wp-command-center/v1)
   * @param {string} token   - WP Command Center API bearer token
   */
  constructor(baseUrl, token) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.token = token;
  }

  // ────────────────────────────────── HTTP helper ──────────────────────────────────

  /**
   * Send an HTTP request to the API.
   *
   * @param  {string} method - GET or POST
   * @param  {string} path   - Endpoint path relative to baseUrl (e.g. '/health')
   * @param  {object} [body] - JSON body for POST requests
   * @return {Promise<object>} Parsed JSON response
   */
  async request(method, path, body = null) {
    const url = this.baseUrl + path;
    const headers = {
      'Authorization': `Bearer ${this.token}`,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };

    const opts = { method, headers };
    if (body !== null && method.toUpperCase() === 'POST') {
      opts.body = JSON.stringify(body);
    }

    const res = await fetch(url, opts);
    const text = await res.text();

    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`Invalid JSON response (HTTP ${res.status}): ${text.substring(0, 200)}`);
    }

    data._http = res.status;
    return data;
  }

  // ────────────────────────────────── Health ──────────────────────────────────

  /**
   * Health check — verify the API is reachable.
   *
   * @return {Promise<object>}
   */
  health() {
    return this.request('GET', '/health');
  }

  // ────────────────────────────────── Operations ──────────────────────────────────

  /**
   * Get all registered operations (metadata only).
   *
   * @return {Promise<object>}
   */
  listOperations() {
    return this.request('GET', '/operations');
  }

  /**
   * Get metadata for a specific operation by ID.
   *
   * @param  {string} operationId - e.g. 'content_seed', 'plugin_manage'
   * @return {Promise<object>}
   */
  getOperation(operationId) {
    return this.request('GET', `/operations/${operationId}`);
  }

  /**
   * Run an operation directly (bypasses the request→approve→execute workflow).
   * Requires a full-access token.
   *
   * @param  {string} operationId - e.g. 'content_seed'
   * @param  {object} payload     - Operation-specific parameters
   * @return {Promise<object>}
   */
  operationRun(operationId, payload = {}) {
    return this.request('POST', `/operations/${operationId}/run`, payload);
  }

  // ────────────────── Operation Request / Approve / Execute workflow ──────────────────

  /**
   * Create an operation request (requires human approval before execution).
   *
   * @param  {string} operationId - Operation identifier
   * @param  {object} payload     - Operation parameters
   * @param  {string} [sessionId] - Optional agent session UUID
   * @param  {string} [taskId]    - Optional agent task UUID
   * @return {Promise<object>}    Created request record with request_id
   */
  operationRequest(operationId, payload = {}, sessionId = '', taskId = '') {
    const body = {
      operation_id: operationId,
      payload,
    };
    if (sessionId) body.session_id = sessionId;
    if (taskId) body.task_id = taskId;

    return this.request('POST', '/operations/requests', body);
  }

  /**
   * List operation requests.
   *
   * @param  {object} [filters] - Optional: status, operation_id, session_id, task_id, limit, offset
   * @return {Promise<object>}
   */
  listOperationRequests(filters = {}) {
    const qs = Object.keys(filters).length
      ? '?' + new URLSearchParams(filters).toString()
      : '';
    return this.request('GET', `/operations/requests${qs}`);
  }

  /**
   * Get a specific operation request by ID.
   *
   * @param  {string} requestId
   * @return {Promise<object>}
   */
  getOperationRequest(requestId) {
    return this.request('GET', `/operations/requests/${requestId}`);
  }

  /**
   * Approve a pending operation request.
   *
   * @param  {string} requestId
   * @return {Promise<object>}
   */
  operationApprove(requestId) {
    return this.request('POST', `/operations/requests/${requestId}/approve`);
  }

  /**
   * Execute an approved operation request.
   *
   * @param  {string} requestId
   * @return {Promise<object>}
   */
  operationExecute(requestId) {
    return this.request('POST', `/operations/requests/${requestId}/execute`);
  }

  /**
   * Reject a pending operation request.
   *
   * @param  {string} requestId
   * @return {Promise<object>}
   */
  operationReject(requestId) {
    return this.request('POST', `/operations/requests/${requestId}/reject`);
  }

  /**
   * Queue an approved operation request for deferred execution.
   *
   * @param  {string} requestId
   * @param  {number} [priority=10]
   * @return {Promise<object>}
   */
  operationQueue(requestId, priority = 10) {
    return this.request('POST', `/operations/requests/${requestId}/queue?priority=${priority}`);
  }

  // ────────────────────────────────── Queue ──────────────────────────────────

  /**
   * List queued operations.
   *
   * @param  {object} [filters] - Optional: status, operation_id, request_id, limit, offset
   * @return {Promise<object>}
   */
  listQueue(filters = {}) {
    const qs = Object.keys(filters).length
      ? '?' + new URLSearchParams(filters).toString()
      : '';
    return this.request('GET', `/operations/queue${qs}`);
  }

  /**
   * List operation execution results.
   *
   * @param  {object} [filters] - Optional: operation_id, queue_id, request_id, status, limit, offset
   * @return {Promise<object>}
   */
  listResults(filters = {}) {
    const qs = Object.keys(filters).length
      ? '?' + new URLSearchParams(filters).toString()
      : '';
    return this.request('GET', `/operations/results${qs}`);
  }

  // ────────────────────────────────── Site Intelligence ──────────────────────────────────

  /**
   * Full site scan: WordPress, PHP, theme, plugins, cache, and server info.
   *
   * @return {Promise<object>}
   */
  siteIntelligence() {
    return this.request('GET', '/site-intelligence');
  }

  // ────────────────────────────────── Diagnostics ──────────────────────────────────

  /**
   * Run diagnostics: performance, security, or woocommerce.
   *
   * @param  {string} [type='performance'] - performance|security|woocommerce
   * @return {Promise<object>}
   */
  diagnostics(type = 'performance') {
    return this.request('GET', `/diagnostics?type=${type}`);
  }

  /**
   * Tail the debug log.
   *
   * @param  {number} [lines=100]
   * @return {Promise<object>}
   */
  debugLog(lines = 100) {
    return this.request('GET', `/diagnostics/debug-log?lines=${lines}`);
  }

  // ────────────────────────────────── Files ──────────────────────────────────

  /**
   * List files in a directory under wp-content/.
   *
   * @param  {string} [path=''] - Relative to wp-content/ (themes, plugins, mu-plugins)
   * @return {Promise<object>}
   */
  listFiles(path = '') {
    const qs = path ? '?path=' + encodeURIComponent(path) : '';
    return this.request('GET', `/files${qs}`);
  }

  /**
   * Read a file's contents.
   *
   * @param  {string} path - Relative to wp-content/
   * @return {Promise<object>}
   */
  fileContent(path) {
    return this.request('GET', '/files/content?path=' + encodeURIComponent(path));
  }

  // ────────────────────────────────── Patches ──────────────────────────────────

  /**
   * Create a patch proposal.
   *
   * @param  {Array<{path: string, modified: string}>} files
   * @param  {string} explanation
   * @param  {string} [riskLevel='low'] - low|medium|high|critical
   * @return {Promise<object>}
   */
  createPatch(files, explanation, riskLevel = 'low') {
    return this.request('POST', '/patches', {
      files,
      explanation,
      risk_level: riskLevel,
    });
  }

  /**
   * Approve a pending patch.
   *
   * @param  {string} patchId
   * @return {Promise<object>}
   */
  approvePatch(patchId) {
    return this.request('POST', `/patches/${patchId}/approve`);
  }

  /**
   * Apply an approved patch.
   *
   * @param  {string} patchId
   * @return {Promise<object>}
   */
  applyPatch(patchId) {
    return this.request('POST', `/patches/${patchId}/apply`);
  }

  /**
   * Roll back an applied patch.
   *
   * @param  {string} patchId
   * @return {Promise<object>}
   */
  rollbackPatch(patchId) {
    return this.request('POST', `/patches/${patchId}/rollback`);
  }

  // ────────────────────────────────── Agent ──────────────────────────────────

  /**
   * Get the agent manifest.
   *
   * @return {Promise<object>}
   */
  agentManifest() {
    return this.request('GET', '/agent/manifest');
  }

  /**
   * Get agent runtime context.
   *
   * @param  {string} [sessionId] - Optional session UUID to scope context
   * @return {Promise<object>}
   */
  agentContext(sessionId = '') {
    const qs = sessionId ? '?session_id=' + encodeURIComponent(sessionId) : '';
    return this.request('GET', `/agent/context${qs}`);
  }
}
