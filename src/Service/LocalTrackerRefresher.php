<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Service;

use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Channel\Model\ChannelInterface;

final class LocalTrackerRefresher
{
    public const OUTCOME_INACTIVE = 'inactive';

    public const OUTCOME_PAUSED = 'paused';

    public const OUTCOME_FRESH = 'fresh';

    public const OUTCOME_LOCAL = 'local';

    public const OUTCOME_REMOTE = 'remote';

    public const OUTCOME_FAILED = 'failed';

    public const REASON_SWITCHED_OFF = 'switched_off';

    public const REASON_TRACKER_DISABLED = 'tracker_disabled';

    private const FILE_PATTERN = '/^fm-[A-Za-z0-9_-]{1,64}\.js$/';

    private const TEMP_PATTERN = '/^\.fm-[A-Za-z0-9_-]{1,64}\.js\.[0-9a-f]{12}\.tmp$/';

    private const HOST_PATTERN = '/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:[0-9]{1,5})?$/';

    private const BASE_URL_PATTERN = '#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#';

    private const MAX_BYTES = 2000000;

    private const MIN_INTERVAL_SECONDS = 540;

    private const BASE_PAUSE_SECONDS = 300;

    private const MAX_PAUSE_SECONDS = 21600;

    private const CONNECT_TIMEOUT_MS = 1000;

    private const TOTAL_TIMEOUT_MS = 3000;

    private const MAX_CHECKED_URLS = 3;

    private const TEMP_MAX_AGE_SECONDS = 3600;

    private const OLD_FILE_MAX_AGE_SECONDS = 604800;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly string $trackerOrigin,
        private readonly string $pluginVersion,
        private readonly string $directory,
        private readonly string $publicPath,
        private readonly ?string $baseUrl = null,
        private readonly ?ObjectRepository $channels = null,
    ) {
    }

    /** @return array{outcome: string, path: ?string, error: ?string} */
    public function refresh(bool $force = false): array
    {
        if (!$this->config->isActive()) {
            $this->remove();

            return $this->result(self::OUTCOME_INACTIVE, []);
        }

        $now = time();
        $state = $this->state();
        $repair = null !== ($state['path'] ?? null) && !$this->intact($state);
        if ($repair) {
            $state = $this->save($state, ['path' => null, 'file' => null, 'error' => 'file_missing'] + $state);
        }
        $trackerWasDisabled = true === ($state['remote'] ?? false) && self::REASON_TRACKER_DISABLED === ($state['error'] ?? null);
        if (LocalTracker::trackerDisabled($this->config->get(ConfigStore::KEY_PLUGIN_CONFIG))) {
            return $trackerWasDisabled
                ? $this->result(self::OUTCOME_REMOTE, $state)
                : $this->switchRemote($state, $now, self::REASON_TRACKER_DISABLED);
        }
        if (!$force && (int) ($state['retryAt'] ?? 0) > $now) {
            return $this->result(self::OUTCOME_PAUSED, $state);
        }
        if (!$force && !$repair && !$trackerWasDisabled && (int) ($state['checkedAt'] ?? 0) > $now - self::MIN_INTERVAL_SECONDS) {
            return $this->result(self::OUTCOME_FRESH, $state);
        }

        $code = (string) $this->config->getConnectionCode();
        $response = $this->http->get(
            rtrim($this->trackerOrigin, '/') . '/api/tracker/bundle?key=' . rawurlencode($code),
            $this->headers('application/json'),
            false,
            null,
            self::CONNECT_TIMEOUT_MS,
            self::TOTAL_TIMEOUT_MS,
        );

        if (404 === $response['status'] || 410 === $response['status']) {
            return $this->switchRemote($state, $now, 'unknown_key');
        }
        if (200 !== $response['status']) {
            return $this->fail($state, $now, 0 === $response['status'] ? 'network' : 'http_' . $response['status'], false);
        }

        $bundle = json_decode($response['body'], true);
        if (!\is_array($bundle)) {
            return $this->fail($state, $now, 'bad_payload', false);
        }
        $this->storeServerSettings($bundle);
        if (false === ($bundle['local'] ?? true)) {
            return $this->switchRemote($state, $now, self::REASON_SWITCHED_OFF);
        }

        $version = $bundle['version'] ?? null;
        $sha = \is_string($bundle['sha256'] ?? null) ? strtolower($bundle['sha256']) : null;
        $content = $bundle['content'] ?? null;
        $origin = $bundle['origin'] ?? null;
        if (!\is_string($version) || 1 !== preg_match(LocalTracker::VERSION_PATTERN, $version)
            || null === $sha || 1 !== preg_match('/^[0-9a-f]{64}$/', $sha)
            || !\is_string($content) || '' === $content || \strlen($content) > self::MAX_BYTES) {
            return $this->fail($state, $now, 'bad_payload', false);
        }
        if (!hash_equals($sha, hash('sha256', $content))) {
            return $this->fail($state, $now, 'bad_sha256', false);
        }
        if (!\is_string($origin) || 1 !== preg_match(LocalTracker::ORIGIN_PATTERN, $origin)) {
            return $this->fail($state, $now, 'bad_origin', false);
        }

        return $this->install($state, $now, $version, $sha, $content, $origin);
    }

    public function remove(): void
    {
        $directory = $this->directory;
        if ('' !== $directory && is_dir($directory)) {
            foreach ($this->entries() as $entry) {
                if (1 === preg_match(self::FILE_PATTERN, $entry) || 1 === preg_match(self::TEMP_PATTERN, $entry)) {
                    @unlink($directory . '/' . $entry);
                }
            }
            $index = $directory . '/index.html';
            if (is_file($index) && 0 === filesize($index)) {
                @unlink($index);
            }
            @rmdir($directory);
        }

        if (null !== $this->config->get(ConfigStore::KEY_LOCAL_TRACKER)) {
            $this->config->delete(ConfigStore::KEY_LOCAL_TRACKER);
        }
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{outcome: string, path: ?string, error: ?string}
     */
    private function install(array $state, int $now, string $version, string $sha, string $content, string $origin): array
    {
        if (1 !== preg_match(LocalTracker::PUBLIC_PATH_PATTERN, $this->publicPath)) {
            return $this->fail($state, $now, 'bad_public_path', true);
        }
        if (!$this->prepareDirectory()) {
            return $this->fail($state, $now, 'not_writable', true);
        }

        $name = 'fm-' . $version . '.js';
        if ($this->matches($this->directory . '/' . $name, $sha)) {
            @touch($this->directory . '/' . $name);
        } elseif (!$this->write($name, $content, $sha)) {
            return $this->fail($state, $now, 'write_failed', true);
        }

        $path = $this->publicPath . '/' . $name;
        $served = $this->served($path, $sha);
        if (null !== $served) {
            return $this->fail($state, $now, $served, true);
        }

        $current = $state['file'] ?? null;
        $previous = \is_string($current) && $current !== $name ? $current : ($state['previous'] ?? null);
        $previous = \is_string($previous) && $previous !== $name && 1 === preg_match(self::FILE_PATTERN, $previous) ? $previous : null;

        $state = $this->save($state, [
            'path' => $path,
            'file' => $name,
            'version' => $version,
            'sha256' => $sha,
            'origin' => $origin,
            'previous' => $previous,
            'checkedAt' => $now,
            'failures' => 0,
            'retryAt' => 0,
            'remote' => false,
            'error' => null,
        ]);
        $this->prune(array_filter([$name, $previous]));

        return $this->result(self::OUTCOME_LOCAL, $state);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{outcome: string, path: ?string, error: ?string}
     */
    private function switchRemote(array $state, int $now, string $reason): array
    {
        $keep = array_values(array_filter([$state['file'] ?? null, $state['previous'] ?? null], '\is_string'));
        $state = $this->save($state, [
            'path' => null,
            'checkedAt' => $now,
            'failures' => 0,
            'retryAt' => 0,
            'remote' => true,
            'error' => $reason,
        ] + $state);
        $this->prune($keep);

        return $this->result(self::OUTCOME_REMOTE, $state);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{outcome: string, path: ?string, error: ?string}
     */
    private function fail(array $state, int $now, string $error, bool $dropLocal): array
    {
        $failures = max(0, (int) ($state['failures'] ?? 0)) + 1;
        $pause = min(self::MAX_PAUSE_SECONDS, self::BASE_PAUSE_SECONDS * (2 ** min($failures, 10)));
        $next = ['failures' => $failures, 'retryAt' => $now + $pause, 'error' => $error];
        if ($dropLocal) {
            $next += ['path' => null, 'file' => null];
        }

        return $this->result(self::OUTCOME_FAILED, $this->save($state, $next + $state));
    }

    /** @param array<mixed> $bundle */
    private function storeServerSettings(array $bundle): void
    {
        if (\is_array($bundle['keeper'] ?? null)) {
            $keeper = LocalTracker::keeper($bundle['keeper']);
            if ($keeper !== $this->config->get(ConfigStore::KEY_KEEPER)) {
                $this->config->set(ConfigStore::KEY_KEEPER, $keeper);
            }
        }
        if (\is_array($bundle['tagAttributes'] ?? null)) {
            $attributes = LocalTracker::tagAttributes($bundle['tagAttributes']);
            if ($attributes !== $this->config->get(ConfigStore::KEY_TAG_ATTRIBUTES)) {
                $this->config->set(ConfigStore::KEY_TAG_ATTRIBUTES, $attributes);
            }
        }
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = $this->config->get(ConfigStore::KEY_LOCAL_TRACKER);

        return \is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     *
     * @return array<string, mixed>
     */
    private function save(array $previous, array $next): array
    {
        $this->config->set(ConfigStore::KEY_LOCAL_TRACKER, $next);

        $path = $next['path'] ?? null;
        $error = $next['error'] ?? null;
        if (($previous['path'] ?? null) !== $path || ($previous['error'] ?? null) !== $error) {
            if (\is_string($path)) {
                $this->logger->log(Logger::TYPE_TRACKER, 'Tracker served from the store', ['path' => $path]);
            } else {
                $this->logger->log(Logger::TYPE_TRACKER, 'Tracker served by Fullmetrix', ['reason' => $error]);
            }
        }

        return $next;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{outcome: string, path: ?string, error: ?string}
     */
    private function result(string $outcome, array $state): array
    {
        $path = $state['path'] ?? null;
        $error = $state['error'] ?? null;

        return [
            'outcome' => $outcome,
            'path' => \is_string($path) ? $path : null,
            'error' => \is_string($error) ? $error : null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function intact(array $state): bool
    {
        $file = $state['file'] ?? null;
        $sha = $state['sha256'] ?? null;
        if (!\is_string($file) || 1 !== preg_match(self::FILE_PATTERN, $file) || !\is_string($sha)) {
            return false;
        }

        return $this->matches($this->directory . '/' . $file, $sha);
    }

    private function matches(string $file, string $sha): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $actual = @hash_file('sha256', $file);

        return \is_string($actual) && hash_equals($sha, $actual);
    }

    private function prepareDirectory(): bool
    {
        $directory = $this->directory;
        if ('' === $directory) {
            return false;
        }
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
                return false;
            }
            @chmod($directory, 0755);
        }
        if (!is_writable($directory)) {
            return false;
        }

        $index = $directory . '/index.html';
        if (!is_file($index)) {
            if (false === @file_put_contents($index, '')) {
                return false;
            }
            @chmod($index, 0644);
        }

        return true;
    }

    private function write(string $name, string $content, string $sha): bool
    {
        $temp = $this->directory . '/.' . $name . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (\strlen($content) !== @file_put_contents($temp, $content)) {
            @unlink($temp);

            return false;
        }
        @chmod($temp, 0644);
        if (!$this->matches($temp, $sha) || !@rename($temp, $this->directory . '/' . $name)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /** @param array<int, string> $keep */
    private function prune(array $keep): void
    {
        $now = time();
        foreach ($this->entries() as $entry) {
            $file = $this->directory . '/' . $entry;
            if (1 === preg_match(self::FILE_PATTERN, $entry)) {
                if (!\in_array($entry, $keep, true) && (int) @filemtime($file) < $now - self::OLD_FILE_MAX_AGE_SECONDS) {
                    @unlink($file);
                }
            } elseif (1 === preg_match(self::TEMP_PATTERN, $entry) && (int) @filemtime($file) < $now - self::TEMP_MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }

    /** @return array<int, string> */
    private function entries(): array
    {
        if ('' === $this->directory || !is_dir($this->directory)) {
            return [];
        }
        $entries = @scandir($this->directory);

        return \is_array($entries) ? $entries : [];
    }

    private function served(string $path, string $sha): ?string
    {
        $urls = $this->baseUrls();
        if ([] === $urls) {
            return 'no_store_url';
        }

        foreach ($urls as $url) {
            $response = $this->http->get(
                $url . $path,
                $this->headers('*/*'),
                false,
                null,
                self::CONNECT_TIMEOUT_MS,
                self::TOTAL_TIMEOUT_MS,
            );
            if (200 === $response['status'] && hash_equals($sha, hash('sha256', $response['body']))) {
                return null;
            }
        }

        return 'not_served';
    }

    /** @return array<int, string> */
    private function baseUrls(): array
    {
        $urls = [];
        $configured = trim((string) $this->baseUrl);
        if (1 === preg_match(self::BASE_URL_PATTERN, $configured)) {
            $urls[] = rtrim($configured, '/');
        }

        $site = $this->config->get(ConfigStore::KEY_SITE_URL);
        if (\is_string($site)) {
            $parts = parse_url($site);
            $host = \is_array($parts) ? ($parts['host'] ?? null) : null;
            if (\is_string($host)) {
                $port = 'https' === ($parts['scheme'] ?? null) && isset($parts['port']) ? ':' . $parts['port'] : '';
                if (1 === preg_match(self::HOST_PATTERN, $host . $port)) {
                    $urls[] = 'https://' . strtolower($host) . $port;
                }
            }
        }

        if (null !== $this->channels) {
            try {
                foreach ($this->channels->findAll() as $channel) {
                    if (!$channel instanceof ChannelInterface || !$channel->isEnabled()) {
                        continue;
                    }
                    $hostname = $channel->getHostname();
                    if (\is_string($hostname) && 1 === preg_match(self::HOST_PATTERN, $hostname)) {
                        $urls[] = 'https://' . strtolower($hostname);
                    }
                }
            } catch (\Throwable) {
            }
        }

        return \array_slice(array_values(array_unique($urls)), 0, self::MAX_CHECKED_URLS);
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        return [
            'User-Agent' => 'FullmetrixSylius/' . $this->pluginVersion,
            'Accept' => $accept,
        ];
    }
}
