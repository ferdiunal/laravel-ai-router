<?php

declare(strict_types=1);

namespace Ferdiunal\LaravelAiRouter\Support;

/**
 * Normalizes and validates runtime custom provider definitions before persistence and routing use.
 */
final class ProviderDefinitionValidator
{
    /** @var list<string> */
    private const UNSAFE_HOST_SUFFIXES = ['.localhost', '.local', '.test', '.internal'];

    /** @var list<string> */
    private const OPENAI_COMPATIBLE_TERMINAL_PATH_SUFFIXES = [
        '/chat/completions',
        '/completions',
        '/models',
    ];

    /** @var list<string> */
    private const SENSITIVE_HEADER_NAMES = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'api-key',
        'api_key',
        'apikey',
        'x-auth',
        'x-authorization',
        'x-authorization-token',
        'x-api-key',
        'x-api-token',
        'x-auth-token',
        'x-access-token',
        'access-token',
        'access_token',
        'auth-token',
        'auth_token',
        'token',
    ];

    /** @var list<string> */
    private const UNSAFE_IP_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b:1::/48',
        '100::/64',
        '100:0:0:1::/64',
        '2001::/23',
        '2001::/32',
        '2001:2::/48',
        '2001:10::/28',
        '2001:20::/28',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * Validate and normalize user-supplied custom OpenAI-compatible provider definition data.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    public static function normalizeOpenAiCompatible(string $platform, array $definition): ?array
    {
        $platform = trim($platform);
        if (self::platformError($platform) !== null) {
            return null;
        }

        $baseUrl = (string) ($definition['base_url'] ?? '');
        if (self::terminalEndpointPathError($baseUrl) !== null) {
            return null;
        }

        $normalizedBaseUrl = self::normalizeBaseUrl($baseUrl);
        if ($normalizedBaseUrl === null) {
            return null;
        }

        $headers = (array) ($definition['headers'] ?? []);
        if (self::headersError($headers) !== null) {
            return null;
        }

        $declaredModels = self::normalizeDeclaredModels($definition['declared_models'] ?? $definition['models'] ?? []);
        if ($declaredModels === null) {
            return null;
        }

        $modelsEndpointEnabled = (bool) ($definition['models_endpoint_enabled'] ?? true);
        $validationMethod = self::normalizeValidationMethod($definition['validation_method'] ?? ($modelsEndpointEnabled ? 'models' : 'chat'));
        if ($validationMethod === null) {
            return null;
        }

        $validationModel = trim((string) ($definition['validation_model'] ?? ''));
        if ($validationModel === '' && $validationMethod === 'chat') {
            $validationModel = (string) ($declaredModels[0]['model_id'] ?? '');
        }

        if ($validationMethod === 'chat' && $validationModel === '') {
            return null;
        }

        $name = trim((string) ($definition['name'] ?? $platform));
        if ($name === '') {
            return null;
        }

        $adapter = (string) ($definition['adapter'] ?? 'openai-compatible');
        if ($adapter !== 'openai-compatible') {
            return null;
        }

        return [
            'name' => $name,
            'adapter' => 'openai-compatible',
            'base_url' => $normalizedBaseUrl,
            'headers' => self::sanitizeHeaders($headers),
            'timeout_ms' => self::normalizeTimeout($definition['timeout_ms'] ?? 15_000),
            'requires_placeholder_key' => (bool) ($definition['requires_placeholder_key'] ?? false),
            'declared_models' => $declaredModels,
            'models_endpoint_enabled' => $modelsEndpointEnabled,
            'validation_method' => $validationMethod,
            'validation_model' => $validationModel === '' ? null : $validationModel,
            'custom' => true,
        ];
    }

    /**
     * Return a validation error for an invalid custom provider platform slug.
     */
    public static function platformError(string $platform): ?string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,62}[a-z0-9]$/', $platform)) {
            return 'Provider platform must be a 3-64 character lowercase slug using letters, numbers, dots, underscores, or dashes.';
        }

        return null;
    }

    /**
     * Return a validation error for an unsafe or invalid custom provider base URL.
     */
    public static function baseUrlError(string $baseUrl, bool $requirePublicDns = false): ?string
    {
        if (($error = self::terminalEndpointPathError($baseUrl)) !== null) {
            return $error;
        }

        if (self::normalizeBaseUrl($baseUrl, requirePublicDns: $requirePublicDns) === null) {
            return 'Provider base URL must be a public HTTPS URL without credentials, query, or fragment.';
        }

        return null;
    }

    /**
     * Normalize a custom provider base URL after validating scheme, host, credentials, and path safety.
     */
    public static function normalizeBaseUrl(string $baseUrl, bool $requirePublicDns = false): ?string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return null;
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts)) {
            return null;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = self::normalizeHost((string) ($parts['host'] ?? ''));
        if ($host === null || self::hostIsUnsafe($host)) {
            return null;
        }

        if ($requirePublicDns && ! self::hostResolvesOnlyToPublicAddresses($host)) {
            return null;
        }

        return self::buildUrl($parts, $host);
    }

    /**
     * Resolve the public IP addresses associated with a custom provider base URL.
     *
     * @return list<string>
     */
    public static function publicAddressesForBaseUrl(string $baseUrl): array
    {
        $normalizedBaseUrl = self::normalizeBaseUrl($baseUrl, requirePublicDns: true);
        if ($normalizedBaseUrl === null) {
            return [];
        }

        $host = self::normalizeHost((string) (parse_url($normalizedBaseUrl, PHP_URL_HOST) ?? ''));
        if ($host === null) {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = self::resolveHost($host);
        if ($addresses === []) {
            return [];
        }

        foreach ($addresses as $address) {
            if (self::hostIsUnsafe($address)) {
                return [];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Return a validation error for unsafe custom provider header input.
     *
     * @param  array<mixed, mixed>  $headers
     */
    public static function headersError(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (! is_string($name) || trim($name) === '') {
                return 'Extra header names must be non-empty strings.';
            }

            $normalizedName = self::normalizeHeaderName($name);
            if ($normalizedName === null) {
                return 'Extra header names must be valid HTTP header tokens.';
            }

            if (self::headerNameIsSensitive($normalizedName)) {
                return 'Extra headers cannot include auth-bearing headers such as Authorization, Proxy-Authorization, X-Api-Key, or token headers.';
            }

            if (! is_scalar($value)) {
                return 'Extra header values must be scalar strings, numbers, or booleans.';
            }

            $headerValue = trim((string) $value);
            if (str_contains($headerValue, "\r") || str_contains($headerValue, "\n")) {
                return 'Extra header values cannot contain CR or LF characters.';
            }
        }

        return null;
    }

    /**
     * Normalize custom provider headers while rejecting authentication-bearing or sensitive header names.
     *
     * @param  array<mixed, mixed>  $headers
     * @return array<string, string>
     */
    public static function sanitizeHeaders(array $headers): array
    {
        if (self::headersError($headers) !== null) {
            return [];
        }

        $sanitized = [];

        foreach ($headers as $name => $value) {
            if ($value === null || ! is_string($name) || ! is_scalar($value)) {
                continue;
            }

            $normalizedName = self::normalizeHeaderName($name);
            if ($normalizedName === null) {
                continue;
            }

            $headerValue = trim((string) $value);
            if ($headerValue === '') {
                continue;
            }

            $sanitized[trim($name)] = $headerValue;
        }

        return $sanitized;
    }

    /**
     * Normalize the custom provider timeout to a bounded millisecond value.
     */
    public static function normalizeTimeout(mixed $timeoutMs): int
    {
        return min(300_000, max(1_000, (int) $timeoutMs));
    }

    /**
     * Return a validation error for declared custom provider model metadata.
     */
    public static function declaredModelsError(mixed $models): ?string
    {
        return self::normalizeDeclaredModels($models) === null
            ? 'Declared models must be a list of model IDs or model metadata objects with a non-empty id/model_id.'
            : null;
    }

    /**
     * Normalize a declared/static custom-provider model list.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function normalizeDeclaredModels(mixed $models): ?array
    {
        if ($models === null || $models === '') {
            return [];
        }

        if (! is_array($models)) {
            return null;
        }

        $normalized = [];
        foreach ($models as $model) {
            $rawModel = $model;
            if (is_string($model) || is_int($model) || is_float($model)) {
                $model = ['model_id' => (string) $model];
            }

            if (! is_array($model)) {
                return null;
            }

            $modelId = trim((string) ($model['model_id'] ?? $model['id'] ?? ''));
            if ($modelId === '') {
                return null;
            }

            $displayName = trim((string) ($model['display_name'] ?? $model['name'] ?? $modelId));
            if ($displayName === '') {
                $displayName = $modelId;
            }

            $row = [
                'model_id' => $modelId,
                'display_name' => $displayName,
                'budget_label' => trim((string) ($model['budget_label'] ?? 'credits-based')) ?: 'credits-based',
                'is_free' => (bool) ($model['is_free'] ?? false),
                'raw_metadata' => is_array($rawModel) ? $rawModel : ['id' => $modelId],
            ];

            foreach (['context_window', 'rpm_limit', 'rpd_limit', 'tpm_limit', 'tpd_limit', 'intelligence_rank', 'speed_rank'] as $integerField) {
                if (array_key_exists($integerField, $model) && $model[$integerField] !== null) {
                    if (! is_scalar($model[$integerField])) {
                        return null;
                    }

                    $row[$integerField] = max(0, (int) $model[$integerField]);
                }
            }

            foreach (['supports_tools', 'auto_enabled'] as $booleanField) {
                if (array_key_exists($booleanField, $model)) {
                    $value = $model[$booleanField];
                    if ($value !== null && ! is_scalar($value)) {
                        return null;
                    }

                    $row[$booleanField] = $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                    if ($value !== null && $row[$booleanField] === null) {
                        return null;
                    }
                }
            }

            $normalized[$modelId] = $row;
        }

        return array_values($normalized);
    }

    /**
     * Normalize the provider credential validation method.
     */
    public static function normalizeValidationMethod(mixed $method): ?string
    {
        $method = strtolower(trim((string) $method));

        return in_array($method, ['models', 'chat'], true) ? $method : null;
    }

    /**
     * Return a validation error when a base URL points at a final OpenAI-compatible endpoint.
     */
    private static function terminalEndpointPathError(string $baseUrl): ?string
    {
        $path = (string) (parse_url(rtrim(trim($baseUrl), '/'), PHP_URL_PATH) ?: '');

        return self::pathUsesTerminalEndpoint($path)
            ? 'Provider base URL must point to the API root (for example https://host/v1), not a final /chat/completions, /completions, or /models endpoint.'
            : null;
    }

    /**
     * Determine whether a URL path targets an OpenAI-compatible terminal endpoint rather than the API root.
     */
    private static function pathUsesTerminalEndpoint(string $path): bool
    {
        $path = '/'.trim(strtolower($path), '/');

        foreach (self::OPENAI_COMPATIBLE_TERMINAL_PATH_SUFFIXES as $suffix) {
            if ($path === $suffix || str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a host name for safety checks and DNS resolution.
     */
    private static function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || str_contains($host, '..')) {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = defined('INTL_IDNA_VARIANT_UTS46')
                ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                : idn_to_ascii($host);

            if ($ascii === false) {
                return null;
            }

            $host = strtolower($ascii);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return null;
            }
        }

        return $host;
    }

    /**
     * Build a normalized URL from parsed URL components for storage and outbound requests.
     *
     * @param  array<string, mixed>  $parts
     */
    private static function buildUrl(array $parts, string $host): ?string
    {
        $url = 'https://'.$host;

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65_535) {
                return null;
            }

            $url .= ':'.$port;
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if (str_contains($path, "\0")) {
            return null;
        }

        if ($path !== '') {
            $url .= '/'.ltrim($path, '/');
        }

        return rtrim($url, '/');
    }

    /**
     * Determine whether a host name is local, private, reserved, or otherwise unsafe for runtime provider calls.
     */
    private static function hostIsUnsafe(string $host): bool
    {
        if (in_array($host, ['localhost', 'metadata.google.internal'], true)) {
            return true;
        }

        foreach (self::UNSAFE_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! self::ipAddressIsPublic($host);
        }

        return ! str_contains($host, '.');
    }

    /**
     * Determine whether DNS resolution for a host returns only public IP addresses.
     */
    private static function hostResolvesOnlyToPublicAddresses(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! self::hostIsUnsafe($host);
        }

        $addresses = self::resolveHost($host);
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (self::hostIsUnsafe($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a host name to candidate IP addresses for public-address validation.
     *
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $field) {
                    $address = $record[$field] ?? null;
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[] = $address;
                    }
                }
            }
        }

        $ipv4Addresses = @gethostbynamel($host);
        if (is_array($ipv4Addresses)) {
            foreach ($ipv4Addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Normalize a header name before sensitivity checks and storage.
     */
    private static function normalizeHeaderName(string $name): ?string
    {
        $name = strtolower(trim($name));

        return preg_match("/^[a-z0-9!#\$%&'*+\-.^_`|~]+$/", $name) === 1 ? $name : null;
    }

    /**
     * Determine whether a header name can carry credentials or secret material and must be rejected.
     */
    private static function headerNameIsSensitive(string $name): bool
    {
        if (in_array($name, self::SENSITIVE_HEADER_NAMES, true)) {
            return true;
        }

        if (str_contains($name, 'authorization')) {
            return true;
        }

        if (preg_match('/(^|[-_])(api[-_]?key|auth|authz|authentication|auth[-_]?token|access[-_]?token|bearer|cookie|credential|security[-_]?token|secret|password|token)([-_]|$)/', $name) === 1) {
            return true;
        }

        $compactName = preg_replace('/[^a-z0-9]/', '', $name) ?? $name;
        foreach (['authorization', 'apikey', 'auth', 'bearer', 'cookie', 'credential', 'securitytoken', 'secret', 'password', 'token'] as $sensitiveFragment) {
            if (str_contains($compactName, $sensitiveFragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether an IP address is globally routable and safe for custom provider calls.
     */
    private static function ipAddressIsPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach (self::UNSAFE_IP_CIDRS as $cidr) {
            if (self::ipMatchesCidr($address, $cidr)) {
                return false;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Determine whether an IP address belongs to the given CIDR block.
     */
    private static function ipMatchesCidr(string $address, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);

        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $byteLength = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($byteLength > 0 && substr($addressBytes, 0, $byteLength) !== substr($networkBytes, 0, $byteLength)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$byteLength]) & $mask) === (ord($networkBytes[$byteLength]) & $mask);
    }
}
