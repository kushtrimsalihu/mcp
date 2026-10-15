<?php

declare(strict_types=1);

namespace McpAi\Tools;

use McpAi\Config\Config;
use PhpMcp\Server\Attributes\Schema;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * MCP tool: get_git_diff.
 *
 * Returns a bounded Git diff for the configured Laravel repo.
 *
 * Scopes:
 *   - `unstaged`      — working tree vs. index (`git diff`)
 *   - `staged`        — index vs. HEAD        (`git diff --cached`)
 *   - `branch`        — HEAD vs. $base_ref    (`git diff <base>...HEAD`)
 *
 * Security:
 *   - Scope is a strict allowlist (never injected into the command line as a string).
 *   - `base_ref` is validated against a safe character class before being passed as
 *     a separate argv entry — the shell is never invoked.
 *   - The git working tree is never modified; only read-only subcommands are used.
 *   - Output is capped to `max_diff_bytes`; the response flags truncation.
 */
final class GetGitDiff
{
    private const ALLOWED_SCOPES = ['unstaged', 'staged', 'branch'];

    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Return a bounded diff for the configured repo.
     *
     * @param string      $scope    One of: unstaged, staged, branch.
     * @param string|null $baseRef  Required when scope=branch. A git ref name
     *                              matching ^[A-Za-z0-9._/\-]{1,100}$.
     *
     * @return array{scope:string,base_ref:?string,is_repo:bool,changed_files:list<array{status:string,path:string}>,diff:string,bytes:int,truncated:bool,message?:string}
     */
    public function diff(
        #[Schema(enum: ['unstaged', 'staged', 'branch'])]
        string  $scope,
        #[Schema(pattern: '^[A-Za-z0-9._/\\-]{1,100}$', maxLength: 100)]
        ?string $baseRef = null,
    ): array {
        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new RuntimeException("get_git_diff: invalid scope '{$scope}'");
        }
        if ($scope === 'branch') {
            if ($baseRef === null || $baseRef === '') {
                throw new RuntimeException('get_git_diff: `base_ref` is required when scope=branch');
            }
            $this->validateRef($baseRef);
        } else {
            $baseRef = null; // ignored for non-branch scopes
        }

        $root = $this->config->laravelRoot;

        // Confirm we're actually in a git repo. If not, return a clear structured response.
        if (!$this->isGitRepo($root)) {
            return [
                'scope'         => $scope,
                'base_ref'      => $baseRef,
                'is_repo'       => false,
                'changed_files' => [],
                'diff'          => '',
                'bytes'         => 0,
                'truncated'     => false,
                'message'       => "The configured repository is not a git working tree: {$root}",
            ];
        }

        // Collect changed files (porcelain output is easy to parse).
        $statusArgs = match ($scope) {
            'unstaged' => ['diff', '--name-status'],
            'staged'   => ['diff', '--cached', '--name-status'],
            'branch'   => ['diff', '--name-status', "{$baseRef}...HEAD"],
        };
        $statusOut = $this->runGit($statusArgs);
        $changedFiles = $this->parseNameStatus($statusOut);

        // Collect the diff itself. --no-color keeps the payload clean for AI consumption.
        $diffArgs = match ($scope) {
            'unstaged' => ['diff', '--no-color'],
            'staged'   => ['diff', '--cached', '--no-color'],
            'branch'   => ['diff', '--no-color', "{$baseRef}...HEAD"],
        };
        $diff = $this->runGit($diffArgs);

        $cap = $this->config->maxDiffBytes;
        $bytes = strlen($diff);
        $truncated = false;
        if ($bytes > $cap) {
            $diff = substr($diff, 0, $cap) . "\n\n[... diff truncated at {$cap} bytes ...]\n";
            $truncated = true;
        }

        $result = [
            'scope'         => $scope,
            'base_ref'      => $baseRef,
            'is_repo'       => true,
            'changed_files' => $changedFiles,
            'diff'          => $diff,
            'bytes'         => $bytes,
            'truncated'     => $truncated,
        ];
        if ($changedFiles === [] && trim($diff) === '') {
            $result['message'] = 'No changes.';
        }

        $this->logger->info('get_git_diff completed', [
            'scope' => $scope, 'base_ref' => $baseRef,
            'files' => count($changedFiles), 'bytes' => $bytes, 'truncated' => $truncated,
        ]);

        return $result;
    }

    private function validateRef(string $ref): void
    {
        if (preg_match('#^[A-Za-z0-9._/\\-]{1,100}$#', $ref) !== 1) {
            throw new RuntimeException("get_git_diff: invalid ref '{$ref}'");
        }
        // Guard against the few characters allowed above that git considers unsafe.
        if (str_contains($ref, '..') || str_starts_with($ref, '-') || str_starts_with($ref, '/')) {
            throw new RuntimeException("get_git_diff: unsafe ref '{$ref}'");
        }
    }

    private function isGitRepo(string $cwd): bool
    {
        $p = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $cwd);
        $p->setTimeout((float) $this->config->gitTimeout);
        $p->run();
        return $p->isSuccessful() && trim($p->getOutput()) === 'true';
    }

    /** @param list<string> $args */
    private function runGit(array $args): string
    {
        $p = new Process(array_merge(['git'], $args), $this->config->laravelRoot);
        $p->setTimeout((float) $this->config->gitTimeout);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'git %s failed: %s',
                implode(' ', $args),
                trim($p->getErrorOutput() ?: $p->getOutput())
            ));
        }
        return $p->getOutput();
    }

    /** @return list<array{status:string,path:string}> */
    private function parseNameStatus(string $out): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            // Lines look like "M\tapp/Foo.php" or "R100\told\tnew".
            $parts = preg_split('/\t/', $line);
            if ($parts === false || count($parts) < 2) {
                continue;
            }
            $status = $parts[0];
            $path   = $parts[count($parts) - 1];
            $rows[] = ['status' => $status, 'path' => $path];
        }
        return $rows;
    }
}
