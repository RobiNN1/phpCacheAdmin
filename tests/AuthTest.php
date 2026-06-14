<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Auth;
use RobiNN\Pca\Config;

final class AuthTest extends TestCase {
    /**
     * @var array<string, string>
     */
    private array $users = [
        'admin' => 'secret',
        'bob'   => 'p@ss w0rd',
    ];

    private ?string $config_file = null;

    protected function tearDown(): void {
        parent::tearDown();
        Config::reset();
        $_GET = [];
        $_POST = [];
        unset($_SESSION['pca_auth_user']);

        if ($this->config_file !== null && is_file($this->config_file)) {
            unlink($this->config_file);
            $this->config_file = null;
        }
    }

    private function setConfig(string $php_array): void {
        $this->config_file = tempnam(sys_get_temp_dir(), 'pca_auth_cfg');
        file_put_contents($this->config_file, '<?php return '.$php_array.';');
        Config::reset();
        Config::setConfigPath($this->config_file);
    }

    public function testValidCredentials(): void {
        $this->assertTrue(Auth::validate($this->users, 'admin', 'secret'));
        $this->assertTrue(Auth::validate($this->users, 'bob', 'p@ss w0rd'));
    }

    public function testWrongPassword(): void {
        $this->assertFalse(Auth::validate($this->users, 'admin', 'wrong'));
        $this->assertFalse(Auth::validate($this->users, 'admin', ''));
    }

    public function testUnknownUser(): void {
        $this->assertFalse(Auth::validate($this->users, 'nobody', 'secret'));
    }

    public function testMissingCredentials(): void {
        $this->assertFalse(Auth::validate($this->users, null, null));
        $this->assertFalse(Auth::validate($this->users, 'admin', null));
        $this->assertFalse(Auth::validate($this->users, null, 'secret'));
    }

    public function testNoUsersConfigured(): void {
        $this->assertFalse(Auth::validate([], 'admin', 'secret'));
    }

    public function testIsEnabledWithUsers(): void {
        $this->setConfig("['authusers' => ['admin' => 'secret']]");
        $this->assertTrue(Auth::isEnabled());
    }

    public function testIsDisabledWhenEmpty(): void {
        $this->setConfig("['authusers' => []]");
        $this->assertFalse(Auth::isEnabled());
    }

    public function testIsDisabledWhenNotConfigured(): void {
        $this->setConfig('[]');
        $this->assertFalse(Auth::isEnabled());
    }

    public function testDisabledAuthDoesNotBlock(): void {
        $this->setConfig('[]');
        Auth::check();
        $this->expectNotToPerformAssertions(); // Returns control instead of rendering the login page.
    }

    public function testCronjobTokenBypassDoesNotBlock(): void {
        $this->setConfig("['authusers' => ['admin' => 'secret'], 'authtoken' => 'tok-123']");
        $_GET['ajax'] = '';
        $_GET['metrics'] = '';
        $_GET['token'] = 'tok-123';

        Auth::check();
        $this->expectNotToPerformAssertions(); // Token grants the cronjob access without a login session.
    }

    public function testLoggedInSessionDoesNotBlock(): void {
        $this->setConfig("['authusers' => ['admin' => 'secret']]");

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION['pca_auth_user'] = 'admin';

        Auth::check();
        $this->expectNotToPerformAssertions(); // Active session passes through.
    }
}
