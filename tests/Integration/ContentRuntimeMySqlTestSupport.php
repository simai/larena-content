<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Tests\Support\ContentRuntimeHarness;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Disposable MySQL database owner for Content acceptance. Destructive cleanup
 * is possible only when both the strict database-name allowlist and the
 * per-run marker row match.
 */
final class ContentRuntimeMySqlTestSupport
{
    private const string DATABASE_PATTERN = '/\Alarena_content_v1_test_[a-f0-9]{12}\z/D';

    private const string BLOB_PATTERN = '/\Alarena-content-v1-mysql-[a-f0-9]{12}\z/D';

    private bool $cleanupPending = true;

    /**
     * @param array{host:string,port:int,username:string,password:string} $credentials
     * @param array<string, mixed> $config
     */
    private function __construct(
        private readonly array $credentials,
        private readonly array $config,
        private readonly string $database,
        private readonly string $marker,
        private readonly string $blobRoot,
        private readonly string $identityHash,
        public readonly ContentRuntimeHarness $runtime,
    ) {
        register_shutdown_function(function (): void {
            if (!$this->cleanupPending) {
                return;
            }

            try {
                $this->cleanup();
            } catch (Throwable) {
                // The synchronous finally block is authoritative.
            }
        });
    }

    public static function requireOptIn(): void
    {
        $enabled = getenv('LARENA_CONTENT_MYSQL_TEST');
        if (!is_string($enabled) || !filter_var($enabled, FILTER_VALIDATE_BOOL)) {
            \PHPUnit\Framework\Assert::markTestSkipped(
                'Real Content MySQL acceptance requires LARENA_CONTENT_MYSQL_TEST=1.',
            );
        }
    }

    public static function create(): self
    {
        self::requireOptIn();
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('content_mysql_pdo_extension_missing');
        }

        $credentials = self::credentials();
        $marker = strtolower(bin2hex(random_bytes(16)));
        $suffix = substr($marker, 0, 12);
        $database = 'larena_content_v1_test_'.$suffix;
        $blobRoot = sys_get_temp_dir().'/larena-content-v1-mysql-'.$suffix;
        self::expect(
            preg_match(self::DATABASE_PATTERN, $database) === 1,
            'content_mysql_database_allowlist_failed',
        );
        self::expect(
            preg_match(self::BLOB_PATTERN, basename($blobRoot)) === 1,
            'content_mysql_blob_allowlist_failed',
        );

        $server = self::server($credentials);
        $identityStatement = $server->query(
            'SELECT @@hostname AS hostname, @@port AS port, VERSION() AS version',
        );
        self::expect(
            $identityStatement instanceof PDOStatement,
            'content_mysql_server_identity_statement_failed',
        );
        $identity = $identityStatement->fetch(PDO::FETCH_ASSOC);
        self::expect(is_array($identity), 'content_mysql_server_identity_unavailable');
        self::expect(
            (int) ($identity['port'] ?? 0) === $credentials['port'],
            'content_mysql_server_port_mismatch',
        );
        $identityHash = hash('sha256', json_encode([
            'host' => strtolower($credentials['host']),
            'port' => (int) $identity['port'],
            'server' => (string) $identity['hostname'],
            'version' => (string) $identity['version'],
        ], JSON_THROW_ON_ERROR));

        $existing = $server->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
        );
        $existing->execute([$database]);
        self::expect(
            (int) $existing->fetchColumn() === 0,
            'content_mysql_refusing_existing_database',
        );

        $config = [
            'driver' => 'mysql',
            'host' => $credentials['host'],
            'port' => $credentials['port'],
            'database' => $database,
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ];

        $created = false;
        try {
            $server->exec(
                'CREATE DATABASE `'.$database
                .'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            );
            $created = true;
            $owned = self::database($credentials, $database);
            $owned->exec(
                'CREATE TABLE `_larena_content_test_marker` ('
                .'`marker` CHAR(32) NOT NULL PRIMARY KEY, '
                .'`created_at` DATETIME(6) NOT NULL'
                .') ENGINE=InnoDB',
            );
            $insert = $owned->prepare(
                'INSERT INTO `_larena_content_test_marker` (`marker`, `created_at`) '
                .'VALUES (?, ?)',
            );
            $insert->execute([$marker, '2026-07-19 12:00:00.000000']);
            if (!is_dir($blobRoot) && !mkdir($blobRoot, 0777, true) && !is_dir($blobRoot)) {
                throw new RuntimeException('content_mysql_blob_root_create_failed');
            }

            $runtime = ContentRuntimeHarness::fromConfig($config, $blobRoot);

            return new self(
                $credentials,
                $config,
                $database,
                $marker,
                $blobRoot,
                $identityHash,
                $runtime,
            );
        } catch (Throwable $exception) {
            if ($created) {
                try {
                    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
                } catch (Throwable) {
                }
            }
            self::removeBlobRoot($blobRoot);

            throw $exception;
        }
    }

    public function secondRuntime(): ContentRuntimeHarness
    {
        return ContentRuntimeHarness::fromExistingConfig(
            $this->config,
            $this->blobRoot,
        );
    }

    public function databaseName(): string
    {
        return $this->database;
    }

    public function identityHash(): string
    {
        return $this->identityHash;
    }

    /** @return array<string, mixed> */
    public function connectionConfig(): array
    {
        return $this->config;
    }

    public function close(): void
    {
        if (!$this->cleanupPending) {
            return;
        }

        $this->runtime->close();
        $this->cleanup();
    }

    private function cleanup(): void
    {
        self::expect(
            preg_match(self::DATABASE_PATTERN, $this->database) === 1,
            'content_mysql_cleanup_database_allowlist_failed',
        );
        $owned = self::database($this->credentials, $this->database);
        $statement = $owned->prepare(
            'SELECT COUNT(*) FROM `_larena_content_test_marker` WHERE `marker` = ?',
        );
        $statement->execute([$this->marker]);
        self::expect(
            (int) $statement->fetchColumn() === 1,
            'content_mysql_cleanup_marker_missing',
        );

        $server = self::server($this->credentials);
        $server->exec('DROP DATABASE `'.$this->database.'`');
        $exists = $server->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
        );
        $exists->execute([$this->database]);
        self::expect(
            (int) $exists->fetchColumn() === 0,
            'content_mysql_cleanup_database_retained',
        );
        self::removeBlobRoot($this->blobRoot);
        $this->cleanupPending = false;
    }

    /** @return array{host:string,port:int,username:string,password:string} */
    private static function credentials(): array
    {
        $declaredPath = getenv('LARENA_CONTENT_MYSQL_ENV_FILE');
        self::expect(
            is_string($declaredPath)
            && $declaredPath !== ''
            && str_starts_with($declaredPath, '/'),
            'content_mysql_env_file_not_explicit',
        );
        self::expect(is_file($declaredPath), 'content_mysql_env_file_missing');

        $directory = dirname($declaredPath);
        $gitRootResult = self::command(
            ['git', 'rev-parse', '--show-toplevel'],
            $directory,
        );
        self::expect($gitRootResult['status'] === 0, 'content_mysql_env_not_in_git_worktree');
        $gitRoot = realpath($gitRootResult['output']);
        self::expect(is_string($gitRoot), 'content_mysql_env_git_root_invalid');

        $realPath = realpath($declaredPath);
        self::expect(
            is_string($realPath)
            && str_starts_with($realPath, $gitRoot.'/'),
            'content_mysql_env_outside_git_worktree',
        );
        $relative = substr($realPath, strlen($gitRoot) + 1);
        self::expect($relative !== '', 'content_mysql_env_relative_path_invalid');
        self::expect(
            self::command(['git', 'check-ignore', '--quiet', '--', $relative], $gitRoot)['status'] === 0,
            'content_mysql_env_not_ignored',
        );
        self::expect(
            self::command(['git', 'ls-files', '--error-unmatch', '--', $relative], $gitRoot)['status'] !== 0,
            'content_mysql_env_tracked',
        );
        $permissions = fileperms($realPath);
        self::expect(
            is_int($permissions) && ($permissions & 0o077) === 0,
            'content_mysql_env_permissions_unsafe',
        );

        $values = self::parseEnv($realPath);
        $required = ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'];
        self::expect(
            array_keys($values) === $required,
            'content_mysql_env_keys_invalid',
        );
        $host = trim($values['DB_HOST']);
        self::expect(
            in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true),
            'content_mysql_host_not_local',
        );
        self::expect(ctype_digit($values['DB_PORT']), 'content_mysql_port_invalid');
        $port = (int) $values['DB_PORT'];
        self::expect($port >= 1 && $port <= 65535, 'content_mysql_port_invalid');
        self::expect($values['DB_USERNAME'] !== '', 'content_mysql_username_invalid');

        return [
            'host' => $host,
            'port' => $port,
            'username' => $values['DB_USERNAME'],
            'password' => $values['DB_PASSWORD'],
        ];
    }

    /** @return array<string, string> */
    private static function parseEnv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::expect(is_array($lines), 'content_mysql_env_unreadable');
        $values = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/\A([A-Z][A-Z0-9_]*)=(.*)\z/D', $trimmed, $matches) !== 1) {
                continue;
            }
            $value = trim($matches[2]);
            if (
                strlen($value) >= 2
                && (
                    ($value[0] === "'" && $value[strlen($value) - 1] === "'")
                    || ($value[0] === '"' && $value[strlen($value) - 1] === '"')
                )
            ) {
                $value = substr($value, 1, -1);
            }
            $values[$matches[1]] = $value;
        }

        return $values;
    }

    /** @param array{host:string,port:int,username:string,password:string} $credentials */
    private static function server(array $credentials): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $credentials['host'],
                $credentials['port'],
            ),
            $credentials['username'],
            $credentials['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    /** @param array{host:string,port:int,username:string,password:string} $credentials */
    private static function database(array $credentials, string $database): PDO
    {
        self::expect(
            preg_match(self::DATABASE_PATTERN, $database) === 1,
            'content_mysql_database_allowlist_failed',
        );

        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $credentials['host'],
                $credentials['port'],
                $database,
            ),
            $credentials['username'],
            $credentials['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    /**
     * @param list<string> $command
     * @return array{status:int,output:string}
     */
    private static function command(array $command, string $cwd): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);
        self::expect(is_resource($process), 'content_mysql_command_start_failed');
        $output = '';
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                $output .= stream_get_contents($pipes[$index]);
                fclose($pipes[$index]);
            }
        }

        return ['status' => proc_close($process), 'output' => trim($output)];
    }

    /** @phpstan-assert true $condition */
    private static function expect(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new RuntimeException($reason);
        }
    }

    private static function removeBlobRoot(string $root): void
    {
        if (!is_dir($root) || preg_match(self::BLOB_PATTERN, basename($root)) !== 1) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
