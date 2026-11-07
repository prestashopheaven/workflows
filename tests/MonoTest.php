<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaHavenMono\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PrestaHavenMono\checkRelease;
use function PrestaHavenMono\kind;
use function PrestaHavenMono\main;
use function PrestaHavenMono\packages;
use function PrestaHavenMono\readJson;
use function PrestaHavenMono\requireVersion;
use function PrestaHavenMono\setVersion;
use function PrestaHavenMono\studly;
use function PrestaHavenMono\validate;
use function PrestaHavenMono\writePhpstanPaths;

/**
 * Tests of the monorepo rules on throw-away monorepos in the temp directory.
 */
final class MonoTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mono-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages', 0777, true);
        $this->writeRoot(['name' => 'prestashopheaven/mono-runtime-core', 'version' => '1.2.0', 'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'runtime']]]]);
        $this->writePackage('cqrs', []);
        $this->writePackage('kit', ['prestashopheaven/runtime-cqrs' => '^1.2', 'php' => '>=8.1']);
        file_put_contents($this->root . '/CHANGELOG.md', "# Changelog\n\n## 1.2.0\n\n- Something.\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAValidMonorepoHasNoProblems(): void
    {
        self::assertSame([], validate($this->root));
    }

    public function testPackagesAreListedSortedWithRepoAndSecretKey(): void
    {
        $packages = packages($this->root);

        self::assertSame(['packages/cqrs', 'packages/kit'], array_column($packages, 'dir'));
        self::assertSame('prestashopheaven/runtime-kit', $packages[1]['repo']);
        self::assertSame('RUNTIME_KIT', $packages[1]['key']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function studlyCases(): iterable
    {
        yield 'one word' => ['kit', 'Kit'];
        yield 'dashes' => ['shared-kernel', 'SharedKernel'];
        yield 'underscores' => ['admin_grid', 'AdminGrid'];
    }

    #[DataProvider('studlyCases')]
    public function testStudly(string $dir, string $expected): void
    {
        self::assertSame($expected, studly($dir));
    }

    /**
     * Every rule of validate(): the change that breaks it and the message that must appear.
     *
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function brokenPackages(): iterable
    {
        yield 'wrong name' => [static fn (array $p): array => ['name' => 'prestashopheaven/kit'] + $p, 'name must be prestashopheaven/runtime-kit'];
        yield 'version field' => [static fn (array $p): array => ['version' => '1.2.0'] + $p, 'no "version"'];
        yield 'license' => [static fn (array $p): array => ['license' => 'AFL-3.0'] + $p, 'license must be MIT'];
        yield 'namespace' => [static function (array $p): array {
            $p['autoload']['psr-4'] = ['PrestaShopHeaven\\ModuleKit\\' => 'src/'];

            return $p;
        }, 'autoload psr-4 must map PrestaShopHeaven\\Runtime\\Kit\\'];
        yield 'branch alias' => [static function (array $p): array {
            $p['extra']['branch-alias']['dev-main'] = '2.x-dev';

            return $p;
        }, 'branch-alias.dev-main must be 1.x-dev'];
        yield 'kind' => [static function (array $p): array {
            $p['extra']['prestashopheaven']['kind'] = 'build';

            return $p;
        }, 'kind must be runtime'];
        yield 'use-when' => [static function (array $p): array {
            $p['extra']['prestashopheaven']['use-when'] = ' ';

            return $p;
        }, 'use-when is empty'];
        yield 'keywords' => [static function (array $p): array {
            $p['extra']['prestashopheaven']['keywords'] = [];

            return $p;
        }, 'keywords is empty'];
        yield 'description' => [static fn (array $p): array => ['description' => ''] + $p, 'description is empty'];
        yield 'internal constraint' => [static function (array $p): array {
            $p['require']['prestashopheaven/runtime-cqrs'] = '^1.0';

            return $p;
        }, 'require it as ^1.2'];
        yield 'runtime requires build' => [static function (array $p): array {
            $p['require']['prestashopheaven/build-testing-php'] = '^1.0';

            return $p;
        }, 'may never require a build package'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('brokenPackages')]
    public function testEveryRuleReportsItsProblem(callable $break, string $message): void
    {
        $file = $this->root . '/packages/kit/composer.json';
        file_put_contents($file, json_encode($break(readJson($file))));

        self::assertStringContainsString($message, implode("\n", validate($this->root)));
    }

    public function testMissingPackageFilesAreReported(): void
    {
        unlink($this->root . '/packages/kit/README.md');

        self::assertContains('packages/kit/README.md is missing.', validate($this->root));
    }

    public function testRootRules(): void
    {
        $this->writeRoot(['name' => 'prestashopheaven/runtime-core', 'version' => '1.2.0', 'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'runtime']]]]);
        self::assertStringContainsString('name must be prestashopheaven/mono-runtime-<category>', implode("\n", validate($this->root)));

        $this->writeRoot(['name' => 'prestashopheaven/mono-runtime-core', 'version' => '1.2', 'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'runtime']]]]);
        self::assertStringContainsString('"version" must be X.Y.Z', implode("\n", validate($this->root)));
    }

    public function testNoPackagesIsAProblem(): void
    {
        $this->remove($this->root . '/packages');
        mkdir($this->root . '/packages');

        self::assertContains('No packages in packages/*/composer.json.', validate($this->root));
    }

    public function testUnknownKindThrows(): void
    {
        $this->writeRoot(['name' => 'prestashopheaven/mono-runtime-core', 'version' => '1.2.0']);

        $this->expectException(\RuntimeException::class);
        kind($this->root);
    }

    public function testMissingComposerJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        readJson($this->root . '/nope.json');
    }

    public function testReleaseNeedsTheSameVersionAChangelogSectionAndAFreeTag(): void
    {
        self::assertSame([], checkRelease($this->root, '1.2.0', false));

        $problems = implode("\n", checkRelease($this->root, '1.3.0', true));
        self::assertStringContainsString('"version" is "1.2.0", the release is 1.3.0', $problems);
        self::assertStringContainsString('CHANGELOG.md has no section "## 1.3.0"', $problems);
        self::assertStringContainsString('Tag v1.3.0 already exists.', $problems);
    }

    public function testSetVersionUpdatesRootAliasesAndInternalConstraints(): void
    {
        setVersion($this->root, '2.0.0');

        self::assertSame('2.0.0', readJson($this->root . '/composer.json')['version']);
        $kit = readJson($this->root . '/packages/kit/composer.json');
        self::assertSame('^2.0', $kit['require']['prestashopheaven/runtime-cqrs']);
        self::assertSame('>=8.1', $kit['require']['php']);
        self::assertSame('2.x-dev', $kit['extra']['branch-alias']['dev-main']);
        self::assertSame([], validate($this->root));
    }

    public function testPhpstanPathsListSrcAndTestsOfEveryPackage(): void
    {
        mkdir($this->root . '/tests/phpstan', 0777, true);
        file_put_contents($this->root . '/tests/phpstan/config.json', '{"versions": ["8.1.0"]}');
        mkdir($this->root . '/packages/kit/tests');

        writePhpstanPaths($this->root);

        $config = readJson($this->root . '/tests/phpstan/config.json');
        self::assertSame(['packages/cqrs/src', 'packages/kit/src', 'packages/kit/tests'], $config['paths']);
        self::assertSame(['8.1.0'], $config['versions']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wrongVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['1.2'];
        yield 'prefix' => ['v1.2.0'];
        yield 'suffix' => ['1.2.0-beta'];
    }

    #[DataProvider('wrongVersions')]
    public function testWrongVersionsAreRefused(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        requireVersion($version);
    }

    public function testCommandLine(): void
    {
        ob_start();
        $validate = main(['validate', '--root=' . $this->root]);
        $matrix = main(['matrix', '--root=' . $this->root]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $validate);
        self::assertSame(0, $matrix);
        self::assertStringContainsString('"key":"RUNTIME_CQRS"', $output);
        self::assertSame(2, main(['unknown']));
        self::assertSame(2, main(['set-version', 'x', '--root=' . $this->root]));
        self::assertSame(1, main(['validate', '--root=' . $this->root . '/missing']));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeRoot(array $data): void
    {
        file_put_contents($this->root . '/composer.json', json_encode($data));
    }

    /**
     * @param array<string, string> $require
     */
    private function writePackage(string $dir, array $require): void
    {
        $path = $this->root . '/packages/' . $dir;
        mkdir($path . '/src', 0777, true);
        foreach (['README.md', 'CHANGELOG.md', 'LICENSE.md', '.gitattributes'] as $file) {
            file_put_contents("$path/$file", "x\n");
        }
        file_put_contents("$path/composer.json", json_encode([
            'name' => "prestashopheaven/runtime-$dir",
            'description' => "The $dir package.",
            'license' => 'MIT',
            'require' => $require ?: new \stdClass(),
            'autoload' => ['psr-4' => ['PrestaShopHeaven\\Runtime\\' . studly($dir) . '\\' => 'src/']],
            'extra' => [
                'branch-alias' => ['dev-main' => '1.x-dev'],
                'prestashopheaven' => ['kind' => 'runtime', 'use-when' => 'Testing.', 'keywords' => ['test']],
            ],
        ]));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove("$path/$entry");
            }
        }
        rmdir($path);
    }
}
