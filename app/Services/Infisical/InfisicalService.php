<?php

namespace App\Services\Infisical;

use App\Exceptions\InfisicalException;
use App\Models\InfisicalIntegration;
use App\Rules\SafeWebhookUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin client for the Infisical API, scoped to a single stored connection.
 *
 * Pinned to the v3 secrets endpoint on purpose: v4 renamed `workspaceId` to
 * `projectId` and `include_imports` to `includeImports` (defaulting it to true),
 * so a silent upgrade would change which secrets we read rather than fail loudly.
 */
class InfisicalService
{
    private const REQUEST_TIMEOUT_SECONDS = 30;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** Renew slightly before the server would expire the token. */
    private const TOKEN_EXPIRY_SAFETY_MARGIN_SECONDS = 60;

    private const MIN_TOKEN_CACHE_SECONDS = 30;

    public function __construct(private InfisicalIntegration $integration) {}

    /**
     * Log in and read one secret to prove the credentials work end to end.
     *
     * @throws InfisicalException
     */
    public function validateConnection(bool $shouldSave = false): void
    {
        try {
            $this->accessToken(forceRefresh: true);

            $this->integration->is_usable = true;
            $this->integration->last_validated_at = now();
        } catch (Throwable $e) {
            $this->integration->is_usable = false;

            throw $e instanceof InfisicalException
                ? $e
                : new InfisicalException($this->friendlyMessage($e));
        } finally {
            if ($shouldSave) {
                $this->integration->save();
            }
        }
    }

    /**
     * Fetch every secret at a path as a flat key => value map.
     *
     * Precedence follows Infisical's own CLI: imported secrets first (later
     * entries in the imports array win, matching the bottom-most-import rule),
     * then secrets defined directly at the path, which override everything.
     *
     * @return array{secrets: array<string, string>, collisions: array<int, string>}
     *
     * @throws InfisicalException
     */
    public function listSecrets(string $projectId, string $environmentSlug, string $secretPath = '/', bool $recursive = false): array
    {
        $payload = $this->authenticatedRequest('get', '/api/v3/secrets/raw', [
            // v3 calls the project id `workspaceId`; there is no `projectId` param.
            'workspaceId' => $projectId,
            'environment' => $environmentSlug,
            'secretPath' => $secretPath ?: '/',
            'recursive' => $recursive ? 'true' : 'false',
            'include_imports' => 'true',
            'expandSecretReferences' => 'true',
            'viewSecretValue' => 'true',
        ]);

        $secrets = [];
        $collisions = [];

        foreach (data_get($payload, 'imports', []) ?? [] as $import) {
            foreach (data_get($import, 'secrets', []) ?? [] as $secret) {
                $key = data_get($secret, 'secretKey');
                if (blank($key) || data_get($secret, 'type', 'shared') !== 'shared') {
                    continue;
                }
                // Later imports override earlier ones (bottom-most import wins).
                $secrets[$key] = (string) (data_get($secret, 'secretValue') ?? '');
            }
        }

        // Direct secrets override imports. With recursive=true the API returns
        // secrets from sub-folders too and defines no winner for a key present in
        // more than one folder, so pick the shallowest path deterministically and
        // report the collision instead of letting array order decide.
        $direct = [];
        foreach (data_get($payload, 'secrets', []) ?? [] as $secret) {
            $key = data_get($secret, 'secretKey');
            if (blank($key) || data_get($secret, 'type', 'shared') !== 'shared') {
                continue;
            }

            $path = (string) (data_get($secret, 'secretPath') ?? $secretPath);
            $candidate = [
                'value' => (string) (data_get($secret, 'secretValue') ?? ''),
                'path' => $path,
            ];

            if (! isset($direct[$key])) {
                $direct[$key] = $candidate;

                continue;
            }

            $collisions[] = $key;
            if ($this->isShallowerPath($path, $direct[$key]['path'])) {
                $direct[$key] = $candidate;
            }
        }

        foreach ($direct as $key => $entry) {
            $secrets[$key] = $entry['value'];
        }

        return [
            'secrets' => $secrets,
            'collisions' => array_values(array_unique($collisions)),
        ];
    }

    private function isShallowerPath(string $candidate, string $current): bool
    {
        $candidateDepth = substr_count(trim($candidate, '/'), '/');
        $currentDepth = substr_count(trim($current, '/'), '/');

        if ($candidateDepth !== $currentDepth) {
            return $candidateDepth < $currentDepth;
        }

        return strcmp($candidate, $current) < 0;
    }

    /**
     * Run a request with the cached machine-identity token, refreshing once if
     * the token was rejected (it may have expired between cache write and use).
     *
     * @throws InfisicalException
     */
    private function authenticatedRequest(string $method, string $path, array $query = []): array
    {
        $response = $this->send($method, $path, $query, $this->accessToken());

        if ($response->status() === 401) {
            $response = $this->send($method, $path, $query, $this->accessToken(forceRefresh: true));
        }

        return $this->decode($response, $path);
    }

    private function send(string $method, string $path, array $query, string $token): Response
    {
        $url = $this->integration->apiBaseUrl().$path;

        try {
            return $this->client($url)
                ->withToken($token)
                ->{$method}($url, $query);
        } catch (Throwable $e) {
            throw new InfisicalException($this->friendlyMessage($e));
        }
    }

    /**
     * @throws InfisicalException
     */
    private function accessToken(bool $forceRefresh = false): string
    {
        $cacheKey = $this->tokenCacheKey();

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        } elseif (filled($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $url = $this->integration->apiBaseUrl().'/api/v1/auth/universal-auth/login';

        try {
            $response = $this->client($url)->post($url, [
                'clientId' => $this->integration->client_id,
                'clientSecret' => $this->integration->client_secret,
            ]);
        } catch (Throwable $e) {
            throw new InfisicalException($this->friendlyMessage($e));
        }

        if ($response->status() === 401) {
            throw new InfisicalException('Infisical rejected the machine identity credentials (401). Check the Client ID and Client Secret.');
        }

        $payload = $this->decode($response, '/api/v1/auth/universal-auth/login');

        $token = data_get($payload, 'accessToken');
        if (blank($token)) {
            throw new InfisicalException('Infisical did not return an access token.');
        }

        $ttl = (int) (data_get($payload, 'expiresIn') ?? 0);
        $ttl = max(self::MIN_TOKEN_CACHE_SECONDS, $ttl - self::TOKEN_EXPIRY_SAFETY_MARGIN_SECONDS);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * Scoped to the credentials so rotating them cannot serve a stale token.
     */
    private function tokenCacheKey(): string
    {
        $fingerprint = substr(hash('sha256', implode('|', [
            $this->integration->apiBaseUrl(),
            (string) $this->integration->client_id,
            (string) $this->integration->client_secret,
        ])), 0, 16);

        return "infisical:token:{$this->integration->id}:{$fingerprint}";
    }

    private function client(string $url): PendingRequest
    {
        // Resolves and pins the host to safe IPs, and blocks redirects, so a
        // self-hosted URL cannot be pointed at cloud metadata or an intranet.
        $options = SafeWebhookUrl::httpClientOptions($url);

        return Http::withOptions($options)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }

    /**
     * @throws InfisicalException
     */
    private function decode(Response $response, string $path): array
    {
        if ($response->status() === 429) {
            throw new InfisicalException('Infisical rate limit exceeded: '.($response->json('message') ?: 'try again shortly.'));
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?: $response->json('error') ?: 'Unknown error';

            throw new InfisicalException("Infisical API error ({$response->status()}) on {$path}: ".(is_string($message) ? $message : 'Unknown error'));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new InfisicalException("Infisical returned an unreadable response for {$path}.");
        }

        return $payload;
    }

    private function friendlyMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'cURL error 6') || str_contains($message, 'could not be resolved')) {
            return 'Could not resolve the Infisical host. Check the Instance URL.';
        }
        if (str_contains($message, 'cURL error 7') || str_contains($message, 'Connection refused')) {
            return 'Could not connect to the Infisical host. Check the Instance URL and that it is reachable from this server.';
        }
        if (str_contains($message, 'cURL error 28') || str_contains($message, 'Operation timed out')) {
            return 'Timed out connecting to Infisical.';
        }
        if (str_contains($message, 'unsafe IP') || str_contains($message, 'DNS pinning')) {
            return 'The Infisical URL points to a local or private address that is not allowed.';
        }

        return $message;
    }
}
