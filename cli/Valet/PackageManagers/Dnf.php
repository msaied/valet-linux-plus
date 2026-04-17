<?php

namespace Valet\PackageManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class Dnf implements PackageManager
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var ServiceManager
     */
    public $serviceManager;
    /**
     * @var array
     */
    public const PHP_FPM_PATTERN_BY_VERSION = [
        '7.0' => 'php-fpm',
        '7.1' => 'php-fpm',
        '7.2' => 'php-fpm',
        '7.3' => 'php-fpm',
        '7.4' => 'php-fpm',
        '8.0' => 'php-fpm',
        '8.1' => 'php-fpm',
        '8.2' => 'php-fpm',
        '8.3' => 'php-fpm',
        '8.4' => 'php-fpm',
        '8.5' => 'php-fpm',
    ];

    private const PACKAGES = [
        'redis' => 'redis',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    /**
     * Cache of resolved php package prefixes, keyed by version.
     * Values: 'remi-scl' (php84-php-*), 'native' (php-*), or null if undetermined.
     *
     * @var array<string, string|null>
     */
    private array $phpConventionCache = [];

    /**
     * Create a new Apt instance.
     */
    public function __construct(CommandLine $cli, ServiceManager $serviceManager)
    {
        $this->cli = $cli;
        $this->serviceManager = $serviceManager;
    }

    /**
     * Determine if the given package is installed.
     */
    public function installed(string $package): bool
    {
        $query = "dnf list installed {$package} | grep {$package} | sed 's_  _\\t_g' | sed 's_\\._\\t_g' | cut -f 1";

        $packages = explode(PHP_EOL, $this->cli->run($query));

        return in_array($package, $packages);
    }

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package): void
    {
        if (!$this->installed($package)) {
            $this->installOrFail($package);
        }
    }

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package): void
    {
        Writer::twoColumnDetail($package, 'Installing');

        $this->cli->run(trim('dnf install -y ' . $package), function ($exitCode, $errorOutput) use ($package) {
            Writer::error(\sprintf('%s: %s', $exitCode, $errorOutput));

            throw new DomainException('Dnf was unable to install [' . $package . '].');
        });
    }

    /**
     * Configure package manager on valet install.
     */
    public function setup(): void
    {
        // Nothing to do
    }

    /**
     * Determine if package manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run('which dnf', function () {
                throw new DomainException('Dnf not available');
            });

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Determine php fpm package name.
     */
    public function getPhpFpmName(string $version): string
    {
        if (!empty(self::PHP_FPM_PATTERN_BY_VERSION[$version])) {
            return self::PHP_FPM_PATTERN_BY_VERSION[$version];
        }

        return $this->resolvePhpConvention($version) === 'remi-scl'
            ? 'php' . str_replace('.', '', $version) . '-php-fpm'
            : 'php-fpm';
    }

    /**
     * Get the `ca-certificates` directory
     */
    public function getCaCertificatesPath(): string
    {
        return '/usr/share/pki/ca-trust-source';
    }

    /**
     * Determine php extension pattern.
     */
    public function getPhpExtensionPrefix(string $version): string
    {
        return $this->resolvePhpConvention($version) === 'remi-scl'
            ? 'php' . str_replace('.', '', $version) . '-php-'
            : 'php-';
    }

    /**
     * Probe dnf to determine which PHP packaging convention is available
     * for the requested version: Remi SCL-style (phpNN-php-*) or native/modular (php-*).
     */
    private function resolvePhpConvention(string $version): string
    {
        if (array_key_exists($version, $this->phpConventionCache) && $this->phpConventionCache[$version] !== null) {
            return $this->phpConventionCache[$version];
        }

        $scl = 'php' . str_replace('.', '', $version) . '-php-fpm';

        if ($this->dnfKnowsPackage($scl)) {
            return $this->phpConventionCache[$version] = 'remi-scl';
        }

        return $this->phpConventionCache[$version] = 'native';
    }

    /**
     * Ask dnf whether a package exists in any enabled repo.
     */
    private function dnfKnowsPackage(string $package): bool
    {
        $escaped = escapeshellarg($package);
        $output = $this->cli->run("dnf info {$escaped} >/dev/null 2>&1 && echo ok || echo no");

        return trim($output) === 'ok';
    }

    /**
     * Restart dnsmasq in Fedora.
     */
    public function restartNetworkManager(): void
    {
        $this->serviceManager->restart('NetworkManager');
    }

    /**
     * Get package name by service.
     */
    public function packageName(string $name): string
    {
        if (isset(self::PACKAGES[$name])) {
            return self::PACKAGES[$name];
        }
        throw new \InvalidArgumentException(\sprintf('Package not found by %s', $name));
    }
}
