<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CIPolicyTest extends TestCase
{
    public function testExternalActionsArePinnedByShaWithReleaseComments(): void
    {
        $paths = array_merge(
            glob(__DIR__ . '/../../.github/workflows/*.{yml,yaml}', GLOB_BRACE),
            glob(__DIR__ . '/../../.github/actions/*/action.{yml,yaml}', GLOB_BRACE),
        );
        $this->assertNotEmpty($paths, 'At least one workflow or composite action must be scanned.');
        $failures = [];

        foreach ($paths as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $lineNumber => $line) {
                if (!preg_match('/uses:\s*([^\s#]+)/', $line, $matches) || str_starts_with($matches[1], './')) {
                    continue;
                }

                if (!preg_match('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $matches[1]) || !preg_match('/#\s*[v\d]/', $line)) {
                    $failures[] = basename($path) . ':' . ($lineNumber + 1) . ' is not a documented SHA pin';
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function testComposerConstraintsKeepKnownAdvisorySafetyFloors(): void
    {
        $composer = $this->json('composer.json');

        $this->assertStringContainsString('5.8.4', $composer['require']['smarty/smarty']);
        $this->assertStringContainsString('2.12.3', $composer['require']['guzzlehttp/psr7']);
        $this->assertStringContainsString('10.5.62', $composer['require-dev']['phpunit/phpunit']);
    }

    public function testComposerMetadataDeclaresMitLicense(): void
    {
        $composer = $this->json('composer.json');

        $this->assertSame('MIT', $composer['license'] ?? null);
    }

    public function testReusableCiUsesOnlySafeHostedPullRequestValidation(): void
    {
        $ci = $this->workflow('ci.yml');

        $this->assertStringContainsString('name: CI', $ci);
        $this->assertStringContainsString('pull_request:', $ci);
        $this->assertStringContainsString('workflow_call:', $ci);
        $this->assertStringContainsString('ready_for_review', $ci);
        $this->assertStringNotContainsString('pull_request_target:', $ci);
        $this->assertStringNotContainsString('self-hosted', $ci);
        $this->assertStringNotContainsString('secrets.', $ci);
        $this->assertStringContainsString('contents: read', $ci);
        $this->assertStringContainsString("php-version: '8.5'", $ci);
        $this->assertStringContainsString("PHP_VERSION_ID >= 80512", $ci);
        $this->assertStringContainsString("fail-on-diff: 'true'", $ci);
        $this->assertStringContainsString('file: ./docker/Dockerfile.dev', $ci);
        $this->assertStringContainsString('timeout-minutes: 60', $ci);
        $this->assertStringContainsString('image: mariadb:10.6', $ci);
        $this->assertStringContainsString('mariadb-client', $ci);
        $this->assertStringContainsString('mariadb --sandbox --version', $ci);
        $this->assertStringContainsString('MIGRATION_DIR="$GITHUB_WORKSPACE/migrations" bash docker/scripts/run-migrations.sh', $ci);
        $this->assertStringNotContainsString('CONFIG_FILE=', $ci);
        $this->assertStringContainsString('run-migrations.sh', $ci);
        $this->assertStringContainsString('DEFAULT_ADMIN_PASSWORD:', $ci);
        $this->assertStringContainsString('APP_SECRET:', $ci);
        $this->assertStringContainsString('persist-credentials: false', $ci);
        $this->assertStringContainsString('command -v mariadb python3 openssl curl setsid', $ci);
        $this->assertStringContainsString('./vendor/bin/phpunit', $this->jobs($ci)['phpunit']);
        $this->assertStringContainsString('./vendor/bin/phpunit tests/Security/CIPolicyTest.php', $this->jobs($ci)['security-checks']);
        $this->assertStringContainsString('import-database-safety-test.sh', $this->jobs($ci)['security-checks']);
        $this->assertStringContainsString('migration-safety-test.sh', $this->jobs($ci)['security-checks']);
        $this->assertStringContainsString('minification-failure-test.sh', $this->jobs($ci)['security-checks']);
        $this->assertStringContainsString('node --test tests/Security/*.test.js', $this->jobs($ci)['security-checks']);
        $containerRuntime = $this->jobs($ci)['container-runtime'];
        $this->assertStringContainsString('POSTPORTAL_TEST_IMAGE=postportal-ci:production bash tests/Security/Runtime/runtime-hardening-test.sh', $containerRuntime);
        $this->assertStringContainsString('POSTPORTAL_TEST_IMAGE=postportal-ci:development bash tests/Security/Runtime/runtime-hardening-test.sh', $containerRuntime);
        $this->assertStringContainsString('import-database-disposable-integration.sh', $this->jobs($ci)['container-runtime']);
        $this->assertStringContainsString('POSTPORTAL_DISPOSABLE_TEST=1', $this->jobs($ci)['container-runtime']);
        $this->assertStringContainsString('POSTPORTAL_SOURCE_DIR=/var/www/html', $this->jobs($ci)['container-runtime']);
        $this->assertStringContainsString('--entrypoint sh', $this->jobs($ci)['container-runtime']);
        $this->assertStringContainsString('/test/newsletter-verification-runtime-test.sh:ro', $this->jobs($ci)['container-runtime']);
        $this->assertStringNotContainsString('cp -a /var/www/html/src', $this->jobs($ci)['container-runtime']);
    }

    public function testCiGateRequiresEveryMandatoryJobToSucceed(): void
    {
        $ci = $this->workflow('ci.yml');

        foreach (['phpunit', 'phpstan', 'composer-audit', 'security-checks', 'readme-consistency', 'container-runtime'] as $job) {
            $this->assertArrayHasKey($job, $this->jobs($ci));
        }

        $gate = $this->jobs($ci)['gate'];
        $this->assertStringContainsString('if: ${{ always() }}', $gate);
        $this->assertStringContainsString('.github/scripts/ci-gate.sh', $gate);
        $this->assertStringContainsString('NEEDS_JSON:', $gate);
        $this->assertStringContainsString('ci-gate-test.sh', $gate);
        $this->assertSame($this->requiredJobs(), $this->needs($gate));
    }

    public function testWorkflowDoesNotInterpolateRefsIntoShellScripts(): void
    {
        $workflowPath = __DIR__ . '/../../.github/workflows/build-container.yml';
        $yaml = file_get_contents($workflowPath);
        $this->assertIsString($yaml);

        $runBlocks = preg_match_all('/run:\s*\|\n((?:\s{10,}.*\n?)*)/', $yaml, $matches);
        $this->assertGreaterThan(0, $runBlocks);

        foreach ($matches[1] as $runBlock) {
            $this->assertStringNotContainsString('${{ github.ref', $runBlock);
            $this->assertStringNotContainsString('${{ github.head_ref', $runBlock);
            $this->assertStringNotContainsString('${{ needs.build-and-push.outputs.branch }}', $runBlock);
        }
    }

    public function testPublicationIsMainOnlyAndVerifiesThePushedDigest(): void
    {
        $workflow = $this->workflow('build-container.yml');
        $jobs = $this->jobs($workflow);

        $this->assertStringContainsString('branches: [main]', $workflow);
        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertStringNotContainsString('pull_request:', $workflow);
        $this->assertStringNotContainsString('workflow_call:', $workflow);
        $this->assertStringContainsString('group: publish-${{ github.ref }}', $workflow);
        $this->assertStringContainsString('cancel-in-progress: false', $workflow);
        $this->assertArrayHasKey('ci', $jobs);
        $this->assertStringContainsString('./.github/workflows/ci.yml', $jobs['ci']);
        $this->assertArrayHasKey('build-and-push', $jobs);
        $this->assertStringContainsString('needs: [ci]', $jobs['build-and-push']);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $jobs['build-and-push']);
        foreach (['build-and-push', 'sign', 'sbom', 'promote'] as $job) {
            $this->assertStringContainsString("github.ref == 'refs/heads/main'", $jobs[$job]);
            $this->assertStringContainsString('environment: production', $jobs[$job]);
            $this->assertStringContainsString('runs-on: self-hosted', $jobs[$job]);
        }
        $this->assertStringContainsString('pull: true', $jobs['build-and-push']);
        $this->assertStringContainsString('no-cache: true', $jobs['build-and-push']);
        $this->assertStringContainsString('php-runtime-security.sh', $jobs['build-and-push']);
        $this->assertStringContainsString(':candidate-${GITHUB_SHA}', $jobs['build-and-push']);
        $this->assertStringNotContainsString(':main', $jobs['build-and-push']);
        $this->assertStringNotContainsString(':latest', $jobs['build-and-push']);
        $this->assertStringContainsString('@${{ needs.build-and-push.outputs.digest }}', $jobs['sign']);
        $this->assertStringContainsString('id-token: write', $jobs['sign']);
        $this->assertSame(1, substr_count($workflow, 'id-token: write'));
        $this->assertStringContainsString('needs: [build-and-push]', $jobs['sign']);
        $this->assertStringContainsString('@${{ needs.build-and-push.outputs.digest }}', $jobs['sbom']);
        $this->assertStringContainsString('needs: [build-and-push]', $jobs['sbom']);
        $this->assertStringContainsString('upload-artifact: false', $jobs['sbom']);
        $this->assertStringContainsString('uses: actions/upload-artifact@', $jobs['sbom']);
        $this->assertStringNotContainsString('id-token: write', $jobs['sbom']);
        $this->assertArrayHasKey('promote', $jobs);
        $this->assertStringContainsString('needs: [build-and-push, sign, sbom]', $jobs['promote']);
        $this->assertStringContainsString('git ls-remote origin refs/heads/main', $jobs['promote']);
        $this->assertStringContainsString('imagetools create --prefer-index=false', $jobs['promote']);
        $this->assertStringContainsString('"$IMAGE:main"', $jobs['promote']);
        $this->assertStringContainsString('"$IMAGE:latest"', $jobs['promote']);
        $this->assertStringContainsString('needs: [ci, build-and-push, sign, sbom, promote]', $jobs['summary']);
        $this->assertStringContainsString('test "$PROMOTE_RESULT" = success', $jobs['summary']);
        $this->assertStringNotContainsString('sync-readme:', $workflow);
        $this->assertStringNotContainsString('git commit', $workflow);
    }

    public function testRulesetTemplatesRestrictBypassAndRequireTheObservedGate(): void
    {
        $required = $this->json('.github/rulesets/main-required.json');
        $review = $this->json('.github/rulesets/main-review.json');

        $this->assertSame([], $required['bypass_actors']);
        $this->assertSame('refs/heads/main', $required['conditions']['ref_name']['include'][0]);
        $this->assertSame('pull_request', $required['rules'][2]['type']);
        $this->assertSame('gate', $required['rules'][3]['parameters']['required_status_checks'][0]['context']);
        $this->assertSame(15368, $required['rules'][3]['parameters']['required_status_checks'][0]['integration_id']);
        $this->assertSame(1, $review['rules'][0]['parameters']['required_approving_review_count']);
        $this->assertTrue($review['rules'][0]['parameters']['require_code_owner_review']);
        $this->assertSame(9312603, $review['bypass_actors'][0]['actor_id']);
        $this->assertSame('exempt', $review['bypass_actors'][0]['bypass_mode']);
    }

    private function workflow(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../.github/workflows/' . $name);
        $this->assertIsString($contents);

        return $contents;
    }

    /** @return array<string, string> */
    private function jobs(string $workflow): array
    {
        preg_match_all('/^  ([a-z-]+):\s*$(.*?)(?=^  [a-z-]+:\s*$|\z)/ms', $workflow, $matches, PREG_SET_ORDER);

        return array_column($matches, 2, 1);
    }

    /** @return list<string> */
    private function requiredJobs(): array
    {
        $script = (string) file_get_contents(__DIR__ . '/../../.github/scripts/ci-gate.sh');
        preg_match('/readonly REQUIRED_JOBS=\(([^)]*)\)/', $script, $matches);

        return preg_split('/\s+/', trim($matches[1] ?? ''), flags: PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<string> */
    private function needs(string $job): array
    {
        preg_match('/needs:\s*\[([^]]*)\]/', $job, $matches);

        return array_map('trim', explode(',', $matches[1] ?? ''));
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../../' . $path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
