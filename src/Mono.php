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

/*
 * The rules of a PrestaHaven monorepo (docs/packages/monorepos.md and rules.md PKG-03..07, PKG-13):
 * - root composer.json: name prestashopheaven/mono-<kind>-<category>, "version" X.Y.Z (the one version, D2),
 *   extra.prestashopheaven.monorepo.kind runtime|build;
 * - packages/<dir>/composer.json: name prestashopheaven/<kind>-<dir>, no "version", license MIT,
 *   autoload psr-4 PrestaShopHeaven\<Runtime|Build>\<Studly dir>\ => src/, branch alias dev-main => X.x-dev,
 *   extra.prestashopheaven.kind/use-when/keywords; packages of the same monorepo require each other as ^X.Y.
 */

namespace PrestaHavenMono;

const VENDOR = 'prestashopheaven';
const LICENSES = ['MIT'];

/**
 * Runs a command line; returns the exit code.
 *
 * @param list<string> $args
 */
function main(array $args): int
{
    $root = '.';
    $rest = [];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $root = substr($arg, 7);
        } else {
            $rest[] = $arg;
        }
    }
    $command = $rest[0] ?? '';
    $version = $rest[1] ?? '';
    $root = rtrim(str_replace('\\', '/', $root), '/');

    try {
        switch ($command) {
            case 'packages':
                echo json_encode(packages($root), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

                return 0;
            case 'matrix':
                echo json_encode(array_map(static fn (array $p): array => ['dir' => $p['dir'], 'repo' => $p['repo'], 'key' => $p['key']], packages($root)), JSON_UNESCAPED_SLASHES), "\n";

                return 0;
            case 'validate':
                return report(validate($root));
            case 'check-release':
                requireVersion($version);

                return report(checkRelease($root, $version, tagExists($root, 'v' . $version)));
            case 'set-version':
                requireVersion($version);
                setVersion($root, $version);
                echo "Version set to $version.\n";

                return report(validate($root));
            case 'phpstan-paths':
                writePhpstanPaths($root);
                echo "tests/phpstan/config.json paths updated.\n";

                return 0;
            default:
                fwrite(STDERR, "Usage: mono.php packages|matrix|validate|check-release <X.Y.Z>|set-version <X.Y.Z>|phpstan-paths [--root=<dir>]\n");

                return 2;
        }
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 2;
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }
}

/**
 * Prints the problems; 0 when there are none.
 *
 * @param list<string> $problems
 */
function report(array $problems): int
{
    foreach ($problems as $problem) {
        fwrite(STDERR, "- $problem\n");
    }
    if ($problems === []) {
        echo "OK.\n";

        return 0;
    }

    return 1;
}

/**
 * @throws \InvalidArgumentException when the version is not X.Y.Z
 */
function requireVersion(string $version): void
{
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        throw new \InvalidArgumentException("The version must be X.Y.Z, got \"$version\".");
    }
}

/**
 * Reads a JSON file as an array.
 *
 * @return array<string, mixed>
 *
 * @throws \RuntimeException when the file is missing or not a JSON object
 */
function readJson(string $file): array
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) {
        throw new \RuntimeException("$file is missing or not valid JSON.");
    }

    return $data;
}

/**
 * Writes an array as composer-style JSON (4 spaces, slashes unescaped, trailing newline).
 *
 * @param array<string, mixed> $data
 */
function writeJson(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * The root composer.json of the monorepo.
 *
 * @return array<string, mixed>
 */
function root(string $root): array
{
    return readJson($root . '/composer.json');
}

/**
 * The kind of the monorepo: runtime or build.
 *
 * @throws \RuntimeException when extra.prestashopheaven.monorepo.kind is missing or unknown
 */
function kind(string $root): string
{
    $kind = root($root)['extra']['prestashopheaven']['monorepo']['kind'] ?? '';
    if (!in_array($kind, ['runtime', 'build'], true)) {
        throw new \RuntimeException('composer.json: extra.prestashopheaven.monorepo.kind must be "runtime" or "build".');
    }

    return $kind;
}

/**
 * The packages of the monorepo, sorted by folder.
 *
 * @return list<array{dir: string, name: string, repo: string, key: string, composer: array<string, mixed>}>
 */
function packages(string $root): array
{
    $packages = [];
    foreach (glob($root . '/packages/*/composer.json') ?: [] as $file) {
        $composer = readJson($file);
        $dir = 'packages/' . basename(dirname($file));
        $name = (string) ($composer['name'] ?? '');
        $packages[] = [
            'dir' => $dir,
            'name' => $name,
            'repo' => $name,
            'key' => strtoupper(str_replace(['/', '-'], ['__', '_'], (string) preg_replace('#^' . VENDOR . '/#', '', $name))),
            'composer' => $composer,
        ];
    }
    usort($packages, static fn (array $a, array $b): int => strcmp($a['dir'], $b['dir']));

    return $packages;
}

/**
 * StudlyCase of a package folder: "shared-kernel" => "SharedKernel".
 */
function studly(string $dir): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $dir)));
}

/**
 * The checks that hold at any time (not only at a release).
 *
 * @return list<string> the problems found
 */
function validate(string $root): array
{
    $problems = [];
    $composer = root($root);
    $kind = kind($root);
    $version = (string) ($composer['version'] ?? '');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $problems[] = 'composer.json: "version" must be X.Y.Z (the one version of the monorepo).';

        return $problems;
    }
    [$major, $minor] = array_map('intval', explode('.', $version));
    if (!preg_match('#^' . VENDOR . '/mono-' . $kind . '-[a-z0-9-]+$#', (string) ($composer['name'] ?? ''))) {
        $problems[] = 'composer.json: name must be ' . VENDOR . "/mono-$kind-<category>.";
    }
    $packages = packages($root);
    if ($packages === []) {
        $problems[] = 'No packages in packages/*/composer.json.';
    }
    $own = array_column($packages, 'name');
    $namespaceRoot = 'PrestaShopHeaven\\' . ucfirst($kind) . '\\';
    foreach ($packages as $package) {
        $p = $package['composer'];
        $dir = basename($package['dir']);
        $where = $package['dir'] . '/composer.json';
        if ($package['name'] !== VENDOR . "/$kind-$dir") {
            $problems[] = "$where: name must be " . VENDOR . "/$kind-$dir (kind and folder).";
        }
        if (isset($p['version'])) {
            $problems[] = "$where: no \"version\" (the tag gives the version; the root composer.json holds it).";
        }
        if (!in_array($p['license'] ?? '', LICENSES, true)) {
            $problems[] = "$where: license must be MIT.";
        }
        $namespace = $namespaceRoot . studly($dir) . '\\';
        if (($p['autoload']['psr-4'][$namespace] ?? null) !== 'src/') {
            $problems[] = "$where: autoload psr-4 must map $namespace to src/.";
        }
        if (($p['extra']['branch-alias']['dev-main'] ?? null) !== "$major.x-dev") {
            $problems[] = "$where: extra.branch-alias.dev-main must be $major.x-dev.";
        }
        $meta = $p['extra']['prestashopheaven'] ?? [];
        if (($meta['kind'] ?? null) !== $kind) {
            $problems[] = "$where: extra.prestashopheaven.kind must be $kind.";
        }
        if (trim((string) ($meta['use-when'] ?? '')) === '') {
            $problems[] = "$where: extra.prestashopheaven.use-when is empty (input for the package index, PKG-13).";
        }
        if (!is_array($meta['keywords'] ?? null) || $meta['keywords'] === []) {
            $problems[] = "$where: extra.prestashopheaven.keywords is empty (input for the package index, PKG-13).";
        }
        if (trim((string) ($p['description'] ?? '')) === '') {
            $problems[] = "$where: description is empty.";
        }
        foreach (['README.md', 'CHANGELOG.md', 'LICENSE.md', '.gitattributes'] as $file) {
            if (!is_file($root . '/' . $package['dir'] . '/' . $file)) {
                $problems[] = $package['dir'] . "/$file is missing.";
            }
        }
        foreach ((array) ($p['require'] ?? []) as $dependency => $constraint) {
            if (in_array($dependency, $own, true) && $constraint !== "^$major.$minor") {
                $problems[] = "$where: $dependency is in this monorepo, require it as ^$major.$minor (one version).";
            }
            if ($kind === 'runtime' && str_starts_with((string) $dependency, VENDOR . '/build-')) {
                $problems[] = "$where: a runtime package may never require a build package ($dependency, PKG-03).";
            }
        }
    }

    return $problems;
}

/**
 * The checks of a release: validate() plus the version, the changelog and the tag.
 *
 * @return list<string> the problems found
 */
function checkRelease(string $root, string $version, bool $tagExists): array
{
    $problems = validate($root);
    $current = (string) (root($root)['version'] ?? '');
    if ($current !== $version) {
        $problems[] = "composer.json: \"version\" is \"$current\", the release is $version (run mono.php set-version $version).";
    }
    $changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
    if (!preg_match('/^## \[?' . preg_quote($version, '/') . '\]?/m', $changelog)) {
        $problems[] = "CHANGELOG.md has no section \"## $version\".";
    }
    if ($tagExists) {
        $problems[] = "Tag v$version already exists.";
    }

    return $problems;
}

/**
 * True when the git tag exists in the repository at $root.
 */
function tagExists(string $root, string $tag): bool
{
    $out = [];
    exec('git -C ' . escapeshellarg($root) . ' tag --list ' . escapeshellarg($tag) . ' 2>&1', $out, $code);

    return $code === 0 && in_array($tag, array_map('trim', $out), true);
}

/**
 * Sets the one version: root "version", every branch alias and every internal constraint.
 */
function setVersion(string $root, string $version): void
{
    [$major, $minor] = array_map('intval', explode('.', $version));
    $composer = root($root);
    $composer['version'] = $version;
    writeJson($root . '/composer.json', $composer);
    $own = array_column(packages($root), 'name');
    foreach (packages($root) as $package) {
        $p = $package['composer'];
        $p['extra']['branch-alias']['dev-main'] = "$major.x-dev";
        foreach (array_keys((array) ($p['require'] ?? [])) as $dependency) {
            if (in_array($dependency, $own, true)) {
                $p['require'][$dependency] = "^$major.$minor";
            }
        }
        writeJson($root . '/' . $package['dir'] . '/composer.json', $p);
    }
}

/**
 * Writes the PHPStan paths of every package (src and tests) into tests/phpstan/config.json.
 */
function writePhpstanPaths(string $root): void
{
    $file = $root . '/tests/phpstan/config.json';
    $config = readJson($file);
    $paths = [];
    foreach (packages($root) as $package) {
        foreach (['src', 'tests'] as $sub) {
            if (is_dir($root . '/' . $package['dir'] . '/' . $sub)) {
                $paths[] = $package['dir'] . '/' . $sub;
            }
        }
    }
    $config['paths'] = $paths;
    writeJson($file, $config);
}
