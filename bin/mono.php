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

/*
 * Helper for the PrestaHaven monorepos (docs/packages in the workspace): lists the packages, checks and sets the one
 * version of a monorepo, and gives the CI its package matrix. Used by the reusable workflows of this repository and
 * by `ps-module mono` locally.
 *
 *   php mono.php packages [--root=<dir>]              JSON list of the packages
 *   php mono.php matrix [--root=<dir>]                the same, compact, for a GitHub Actions matrix
 *   php mono.php validate [--root=<dir>]              names, namespaces, licenses, branch aliases, internal constraints
 *   php mono.php check-release <X.Y.Z> [--root=<dir>] validate + root version, changelog, tag not yet there
 *   php mono.php set-version <X.Y.Z> [--root=<dir>]   root version, branch aliases and internal constraints to X.Y.Z
 *   php mono.php phpstan-paths [--root=<dir>]         writes tests/phpstan/config.json paths for every package
 *
 * Exit code 0 = ok, 1 = a rule failed (the messages say which), 2 = wrong usage.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Mono.php';

exit(PrestaHavenMono\main(array_slice($argv, 1)));
