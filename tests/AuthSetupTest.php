<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Auth;
use RobiNN\Pca\AuthSetup;
use RobiNN\Pca\Config;

final class AuthSetupTest extends TestCase {
    private string $dir;

    private string $file;

    protected function setUp(): void {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/pca_setup_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->file = $this->dir.'/config.php';

        Config::reset();
        Config::setConfigPath($this->file);

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION['csrf_token'] = 'pu-test-csrf';
        $_POST = ['csrf_token' => 'pu-test-csrf'];
    }

    protected function tearDown(): void {
        parent::tearDown();

        Config::reset();
        $_POST = [];
        unset($_SESSION['csrf_token'], $_SESSION['pca_auth_user']);

        foreach ((array) glob($this->dir.'/*') as $file) {
            unlink((string) $file);
        }

        rmdir($this->dir);
    }

    private function config(string $authusers): void {
        file_put_contents($this->file, "<?php return [\n    'console' => true,\n    'authusers' => [".$authusers."],\n    'listview' => 'table',\n];\n");
    }

    /**
     * @return array<string, string>
     */
    private function save(string $user = 'admin', string $password = 'test-pass-123', ?string $repeat = null): array {
        $_POST['authuser'] = $user;
        $_POST['authpassword'] = $password;
        $_POST['authpassword2'] = $repeat ?? $password;

        return (array) json_decode(AuthSetup::handle(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function written(): array {
        return (array) include $this->file;
    }

    public function testWritesTheUserIntoTheConfig(): void {
        $this->config('');

        $this->assertArrayHasKey('message', $this->save());

        $users = $this->written()['authusers'];

        $this->assertTrue(Auth::validate($users, 'admin', 'test-pass-123'));
    }

    public function testPasswordIsStoredAsHash(): void {
        $this->config('');
        $this->save();

        $stored = (string) $this->written()['authusers']['admin'];

        $this->assertNotSame('test-pass-123', $stored);
        $this->assertNotNull(password_get_info($stored)['algo']);
    }

    public function testRestOfTheConfigIsUntouched(): void {
        $this->config("\n        //'admin' => 'your-password',\n    ");
        $this->save();

        $config = $this->written();

        $this->assertTrue($config['console']);
        $this->assertSame('table', $config['listview']);
    }

    public function testConfigIsCreatedFromTheDistFile(): void {
        $this->assertFileDoesNotExist($this->file);
        $this->assertArrayHasKey('message', $this->save());
        $this->assertFileExists($this->file);

        $this->assertTrue(Auth::validate($this->written()['authusers'], 'admin', 'test-pass-123'));
    }

    public function testSessionIsLoggedInAsTheNewUser(): void {
        $this->config('');
        $this->save();

        $this->assertSame('admin', $_SESSION['pca_auth_user'] ?? null);
    }

    public function testShortPasswordIsRejected(): void {
        $this->config('');

        $this->assertSame('The password has to be at least 8 characters long.', $this->save('admin', 'short')['error']);
        $this->assertSame([], $this->written()['authusers']);
    }

    public function testMismatchedPasswordsAreRejected(): void {
        $this->config('');

        $this->assertSame('The passwords do not match.', $this->save('admin', 'test-pass-123', 'test-pass-456')['error']);
    }

    public function testPasswordLongerThanBcryptHashesIsRejected(): void {
        $this->config('');

        $this->assertArrayHasKey('error', $this->save('admin', str_repeat('a', 73)));
    }

    public function testInvalidUsernameIsRejected(): void {
        $this->config('');

        $this->assertArrayHasKey('error', $this->save("admin' => 'x", 'test-pass-123'));
        $this->assertSame([], $this->written()['authusers']);
    }

    public function testInvalidCsrfTokenIsRejected(): void {
        $this->config('');
        $_POST['csrf_token'] = 'wrong';

        $this->assertSame('Invalid CSRF token.', $this->save()['error']);
    }

    public function testRefusedWhenUsersAlreadyExist(): void {
        $this->config("'admin' => 'secret'");

        $this->assertSame('Authentication is already enabled.', $this->save()['error']);
        $this->assertFalse(AuthSetup::isAllowed());
    }

    public function testRefusedWhenTheNoticeIsTurnedOff(): void {
        file_put_contents($this->file, "<?php return ['authusers' => [], 'authwarning' => false];\n");

        $this->assertArrayHasKey('error', $this->save());
        $this->assertFalse(AuthSetup::isAllowed());
    }

    public function testConfigWithoutTheOptionGetsASnippet(): void {
        file_put_contents($this->file, "<?php return ['console' => true];\n");

        $result = $this->save();

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString("'authusers' => [", (string) $result['snippet']);
        $this->assertStringContainsString("'admin' =>", (string) $result['snippet']);
    }

    public function testNothingIsWrittenBesidesTheConfig(): void {
        $this->config('');
        $this->save();

        $this->assertSame(['config.php'], array_map(basename(...), (array) glob($this->dir.'/*')));
    }
}
