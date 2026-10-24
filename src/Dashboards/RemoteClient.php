<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards;

use JsonException;

/**
 * Reads the OPcache, APCu, and Realpath cache of another server from its pca-remote.php.
 */
class RemoteClient {
    /**
     * The version of the response this client understands, PROTOCOL in pca-remote.php.
     */
    public const PROTOCOL = 1;

    /**
     * The extension each section needs on the remote server.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = ['opcache' => 'OPcache', 'apcu' => 'APCu'];

    /**
     * One request per section and page, a response with more flags also answers the one with fewer.
     *
     * @var array<string, array{flags: array<int, string>, data: array<string, mixed>}>
     */
    private array $cache = [];

    /**
     * @param array<string, mixed> $server
     */
    public function __construct(private readonly array $server) {
    }

    public function name(): string {
        return (string) ($this->server['name'] ?? $this->host());
    }

    public function host(): string {
        return (string) parse_url((string) ($this->server['url'] ?? ''), PHP_URL_HOST);
    }

    /**
     * Makes the request, call it where the error can be shown. The dashboards read the response with fetched().
     *
     * @param array<int, string> $flags E.g. 'scripts' for OPcache, 'keys' for APCu.
     *
     * @return array<string, mixed>
     *
     * @throws DashboardException
     */
    public function fetch(string $section, array $flags = []): array {
        if (isset($this->cache[$section]) && array_diff($flags, $this->cache[$section]['flags']) === []) {
            return $this->cache[$section]['data'];
        }

        $data = $this->decode($this->request($this->url($section, $flags)));

        if (($data['protocol'] ?? null) !== self::PROTOCOL) {
            throw new DashboardException('pca-remote.php on '.$this->name().' is a different version, update it there.');
        }

        if (($data['loaded'] ?? false) !== true) {
            throw new DashboardException('The '.(self::EXTENSIONS[$section] ?? $section).' extension is not loaded on '.$this->name().'.');
        }

        // Loaded but turned off, there are no statistics to show.
        if ($section === 'apcu' && (!is_array($data['cache_info'] ?? null) || !is_array($data['sma_info'] ?? null))) {
            throw new DashboardException('APCu is not enabled on '.$this->name().' (apc.enabled).');
        }

        $this->cache[$section] = ['flags' => $flags, 'data' => $data];

        return $data;
    }

    /**
     * The response fetch() got for the section, empty before it was fetched.
     *
     * @return array<string, mixed>
     */
    public function fetched(string $section): array {
        return $this->cache[$section]['data'] ?? [];
    }

    /**
     * @param array<int, string> $flags
     *
     * @throws DashboardException
     */
    private function url(string $section, array $flags): string {
        $url = (string) ($this->server['url'] ?? '');

        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new DashboardException('The URL of '.$this->name().' has to start with http:// or https://.');
        }

        $query = http_build_query(['section' => $section] + array_fill_keys($flags, 1));

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DashboardException
     */
    private function decode(string $body): array {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DashboardException('The response from '.$this->name().' is not from pca-remote.php.');
        }

        if (!is_array($data)) {
            throw new DashboardException('The response from '.$this->name().' is not from pca-remote.php.');
        }

        if (isset($data['error'])) {
            throw new DashboardException($this->name().': '.$data['error']);
        }

        return $data;
    }

    /**
     * @throws DashboardException
     */
    protected function request(string $url): string {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new DashboardException('Remote servers need allow_url_fopen enabled.');
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => "Accept: application/json\r\nX-PCA-Token: ".(($this->server['token'] ?? ''))."\r\n",
                'timeout'         => (float) ($this->server['timeout'] ?? 3),
                'ignore_errors'   => true, // pca-remote.php explains a refusal in the body.
                'follow_location' => 0, // A redirect would send the token wherever it points.
            ],
            'ssl'  => (array) ($this->server['ssl'] ?? []),
        ]);

        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            $error = error_get_last()['message'] ?? '';

            throw new DashboardException('Could not reach '.$this->name().'. '.preg_replace('~^fopen\([^)]*\): ~', '', $error));
        }

        $status = $this->statusCode((array) (stream_get_meta_data($stream)['wrapper_data'] ?? []));
        $body = (string) stream_get_contents($stream);
        fclose($stream);

        if ($status >= 300 && $status < 400) {
            throw new DashboardException($this->name().' redirects pca-remote.php, set the final URL.');
        }

        // pca-remote.php explains its own refusals, anything else is the web server.
        if ($status >= 400 && !json_validate($body)) {
            throw new DashboardException($this->name().' answered with HTTP '.$status.', check the URL.');
        }

        return $body;
    }

    /**
     * @param array<int, string> $headers
     */
    private function statusCode(array $headers): int {
        // The last status line is the response, should a redirect ever be followed.
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
