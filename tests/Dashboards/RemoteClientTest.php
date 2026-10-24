<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards;

use PHPUnit\Framework\TestCase;
use Random\RandomException;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\RemoteClient;

/**
 * Against pca-remote.php served by the PHP built-in server, the client is the only part that speaks HTTP.
 */
final class RemoteClientTest extends TestCase {
    private const TOKEN = 'pu-test-token-0123456789abcdef0123456789';

    /**
     * @var resource|null
     */
    private static $process;

    private static string $docroot = '';

    private static int $port = 0;

    /**
     * @throws RandomException
     */
    public static function setUpBeforeClass(): void {
        self::$docroot = sys_get_temp_dir().'/pca_remote_'.bin2hex(random_bytes(6));
        mkdir(self::$docroot);
        copy(__DIR__.'/../../pca-remote.php', self::$docroot.'/pca-remote.php');
        file_put_contents(self::$docroot.'/redirect.php', '<?php header("Location: /pca-remote.php", true, 302);');
        file_put_contents(self::$docroot.'/html.php', '<?php echo "<html></html>";');

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $env = ['PCA_REMOTE_TOKEN' => self::TOKEN, 'PATH' => (string) getenv('PATH')];
        $command = [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, '-t', self::$docroot];
        $process = proc_open($command, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, self::$docroot, $env);

        if ($process === false) {
            self::markTestSkipped('Could not start the PHP built-in server.');
        }

        self::$process = $process;

        for ($i = 0; $i < 100; $i++) {
            $connection = @stream_socket_client('tcp://127.0.0.1:'.self::$port, $errno, $errstr, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(20_000);
        }

        self::markTestSkipped('The PHP built-in server did not start.');
    }

    public static function tearDownAfterClass(): void {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        foreach ((array) glob(self::$docroot.'/*') as $file) {
            unlink((string) $file);
        }

        @rmdir(self::$docroot);
    }

    private function client(string $path = '/pca-remote.php', string $token = self::TOKEN): RemoteClient {
        return new RemoteClient(['name' => 'Web', 'url' => 'http://127.0.0.1:'.self::$port.$path, 'token' => $token]);
    }

    /**
     * @throws DashboardException
     */
    public function testFetch(): void {
        $data = $this->client()->fetch('realpath');

        $this->assertSame(RemoteClient::PROTOCOL, $data['protocol']);
        $this->assertArrayHasKey('entries', $data);
    }

    /**
     * @throws DashboardException
     */
    public function testWrongToken(): void {
        $this->expectExceptionObject(new DashboardException('Web: Invalid token.'));

        $this->client(token: 'wrong')->fetch('realpath');
    }

    /**
     * @throws DashboardException
     */
    public function testUnknownSection(): void {
        $this->expectExceptionObject(new DashboardException('Web: Unknown section.'));

        $this->client()->fetch('redis');
    }

    /**
     * @throws DashboardException
     */
    public function testNotFound(): void {
        $this->expectExceptionObject(new DashboardException('Web answered with HTTP 404, check the URL.'));

        $this->client('/missing.php')->fetch('realpath');
    }

    /**
     * @throws DashboardException
     */
    public function testRedirectIsNotFollowed(): void {
        $this->expectExceptionObject(new DashboardException('Web redirects pca-remote.php, set the final URL.'));

        $this->client('/redirect.php')->fetch('realpath');
    }

    /**
     * @throws DashboardException
     */
    public function testResponseNotFromPcaRemote(): void {
        $this->expectExceptionObject(new DashboardException('The response from Web is not from pca-remote.php.'));

        $this->client('/html.php')->fetch('realpath');
    }

    /**
     * @throws DashboardException
     */
    public function testUnreachableServer(): void {
        $client = new RemoteClient(['name' => 'Web', 'url' => 'http://127.0.0.1:1/pca-remote.php', 'token' => self::TOKEN, 'timeout' => 1]);

        $this->expectException(DashboardException::class);
        $this->expectExceptionMessageIsOrContains('Could not reach Web.');

        $client->fetch('realpath');
    }

    /**
     * @throws DashboardException
     */
    public function testOnlyHttpUrls(): void {
        $client = new RemoteClient(['name' => 'Web', 'url' => 'file:///etc/passwd', 'token' => self::TOKEN]);

        $this->expectExceptionObject(new DashboardException('The URL of Web has to start with http:// or https://.'));

        $client->fetch('realpath');
    }
}
