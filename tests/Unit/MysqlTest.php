<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Mysql;
use Valet\PhpFpm;
use Valet\Tests\TestCase;

use function Valet\swap;

class MysqlTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;

    /**
     * Package names probed by Mysql::detectInstalledPackage() in order.
     * Mirrors the candidate list produced from PackageManager::packageName('mysql'/'mariadb')
     * merged with MYSQL_PACKAGE_CANDIDATES and MARIADB_PACKAGE_CANDIDATES.
     */
    private const PROBED_PACKAGES = [
        'mysql-server',
        'mysql-community-server',
        'mysql',
        'mariadb-server',
        'mariadb',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);
    }

    /**
     * Configure the package manager mock so that the given package (if any) reports
     * as installed while all other candidates return false.
     */
    private function mockDetection(?string $installedPackage): void
    {
        $this->packageManager->shouldReceive('packageName')->with('mysql')->andReturn('mysql-server');
        $this->packageManager->shouldReceive('packageName')->with('mariadb')->andReturn('mariadb-server');

        foreach (self::PROBED_PACKAGES as $candidate) {
            $this->packageManager
                ->shouldReceive('installed')
                ->with($candidate)
                ->andReturn($candidate === $installedPackage);
        }
    }

    private function buildMysql(): Mysql
    {
        return new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );
    }

    public function packageDataProvider(): array
    {
        return [
            'mysql' => [
                false,
                'mysql',
                'mysql-server',
            ],
            'mariadb' => [
                true,
                'mariadb',
                'mariadb-server',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider packageDataProvider
     */
    public function itWillInstallSuccessfully(bool $useMariaDB, string $packageName, string $packageServerName): void
    {
        Writer::fake();
        $this->mockDetection(null);
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);
        $phpFpm->shouldReceive('getCurrentVersion')->once()->andReturn('8.2');

        $this->packageManager
            ->shouldReceive('getPhpExtensionPrefix')
            ->with('8.2')
            ->once()
            ->andReturn('php8.2-');

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->with('php8.2-mysql')
            ->once();

        $this->packageManager
            ->shouldReceive('installOrFail')
            ->with($packageServerName)
            ->once()
            ->andReturnFalse();

        $this->serviceManager
            ->shouldReceive('enable')
            ->with($packageName)
            ->once()
            ->andReturnFalse();

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturn('');

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn([]);

        $this->config
            ->shouldReceive('set')
            ->with('mysql', ['user' => 'valet', 'password' => ''])
            ->once()
            ->andReturn([]);

        $this->buildMysql()->install($useMariaDB);
    }

    /**
     * @test
     */
    public function itWillNotOverrideWhenInstalled(): void
    {
        $this->mockDetection('mysql-server');

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn(['user' => 'valet', 'password' => 'valet-password']);

        $this->packageManager->shouldNotReceive('installOrFail')->with('mysql-server');
        $this->serviceManager->shouldNotReceive('enable')->with('mysql');

        $this->buildMysql()->install();
    }

    /**
     * @test
     */
    public function itWillNotOverrideWhenMariaDbInstalled(): void
    {
        $this->mockDetection('mariadb-server');

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn(['user' => 'valet', 'password' => 'valet-password']);

        $this->packageManager->shouldNotReceive('installOrFail')->with('mariadb-server');
        $this->serviceManager->shouldNotReceive('enable')->with('mariadb');

        $this->buildMysql()->install();
    }

    /**
     * @test
     */
    public function itDetectsMysqlCommunityServer(): void
    {
        $this->mockDetection('mysql-community-server');

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn(['user' => 'valet', 'password' => 'valet-password']);

        $this->packageManager->shouldNotReceive('installOrFail');

        $this->buildMysql()->install();
    }

    /**
     * @test
     */
    public function itWillStopService(): void
    {
        $this->mockDetection('mysql-server');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $this->buildMysql()->stop();
    }

    /**
     * @test
     */
    public function itWillRestartService(): void
    {
        $this->mockDetection('mysql-server');

        $this->serviceManager
            ->shouldReceive('restart')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $this->buildMysql()->restart();
    }

    /**
     * @test
     */
    public function itWillUninstallService(): void
    {
        $this->mockDetection('mysql-server');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $this->buildMysql()->uninstall();
    }
}
