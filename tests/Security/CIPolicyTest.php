<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CIPolicyTest extends TestCase
{
    /**
     * Test that all external actions in all workflows use exactly 40-character hex SHAs.
     * Scans .github/workflows and .github/actions directories.
     * Local actions (uses: ./) and Docker syntax are permitted.
     * Each external pin must have an adjacent human-readable release comment.
     */
    public function testExternalActionsArePinnedBySha(): void
    {
        $failures = [];

        // Scan all workflows
        $workflowDir = __DIR__ . '/../../.github/workflows';
        $this->assertDirectoryExists($workflowDir);

        $workflowFiles = glob($workflowDir . '/*.{yml,yaml}', GLOB_BRACE);
        $this->assertNotEmpty($workflowFiles, 'No workflow files found');

        foreach ($workflowFiles as $workflowPath) {
            $failures = array_merge($failures, $this->checkYamlFile($workflowPath));
        }

        // Scan all composite actions
        $actionsDir = __DIR__ . '/../../.github/actions';
        if (is_dir($actionsDir)) {
            foreach (glob($actionsDir . '/*/action.{yml,yaml}', GLOB_BRACE) as $actionPath) {
                $failures = array_merge($failures, $this->checkYamlFile($actionPath));
            }
        }

        $this->assertEmpty($failures, "Found action pinning violations:\n" . implode("\n", $failures));
    }

    /**
     * Check a single YAML file for action pin compliance.
     * @return array<int, string> List of failure messages
     */
    private function checkYamlFile(string $filePath): array
    {
        $failures = [];
        $yaml = file_get_contents($filePath);
        $this->assertIsString($yaml);

        $lines = explode("\n", $yaml);

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            if (empty($trimmed) || strpos($trimmed, 'uses:') === false) {
                continue;
            }

            if (!preg_match('/uses:\s*(.+?)(?:\s*#.*)?$/', $trimmed, $matches)) {
                continue;
            }

            $usesValue = trim($matches[1]);

            // Permit local actions and Docker syntax
            if (strpos($usesValue, './') === 0 || strpos($usesValue, 'docker://') === 0) {
                continue;
            }

            // External action: must be owner/action@40hexsha with release comment
            if (!preg_match('/^[a-zA-Z0-9\-_.]+\/[a-zA-Z0-9\-_.]+@[0-9a-f]{40}$/', $usesValue)) {
                $failures[] = sprintf(
                    "%s:%d: Invalid format (must be owner/action@40hexsha): %s",
                    basename($filePath),
                    $lineNum + 1,
                    $usesValue
                );
                continue;
            }

            // Check for adjacent release comment
            $hasComment = preg_match('/#\s*[v\d]/', $line);
            if (!$hasComment && isset($lines[$lineNum + 1])) {
                $hasComment = preg_match('/#\s*[v\d]/', $lines[$lineNum + 1]);
            }

            if (!$hasComment) {
                $failures[] = sprintf(
                    "%s:%d: Missing release comment for: %s",
                    basename($filePath),
                    $lineNum + 1,
                    $usesValue
                );
            }
        }

        return $failures;
    }

    /**
     * Test that composer.json requires minimum versions for known advisories.
     */
    public function testComposerConstraintsIncludeSafetyFloors(): void
    {
        $composerPath = __DIR__ . '/../../composer.json';
        $this->assertFileExists($composerPath);

        $composer = json_decode(file_get_contents($composerPath), associative: true);
        $this->assertIsArray($composer);

        // Verify minimum versions for known CVEs
        $require = $composer['require'] ?? [];

        // Smarty >= 5.8.4 for SEC-14 advisories
        $this->assertArrayHasKey('smarty/smarty', $require, 'Smarty must be in require');
        $this->assertStringContainsString(
            '5.8.4',
            $require['smarty/smarty'],
            'Smarty must be >= 5.8.4 to address CVE-2026-62996, CVE-2026-62993, CVE-2026-62992'
        );

        // Guzzle PSR-7 >= 2.12.3 for SEC-14 CRLF/host confusion advisories
        $this->assertArrayHasKey('guzzlehttp/psr7', $require, 'Guzzle PSR-7 must be in require');
        $this->assertStringContainsString(
            '2.12.3',
            $require['guzzlehttp/psr7'],
            'Guzzle PSR-7 must be >= 2.12.3'
        );

        // PHPUnit >= 10.5.62 for development (CVE-2026-24765 in >=10.0.0,<10.5.62)
        $requireDev = $composer['require-dev'] ?? [];
        $this->assertArrayHasKey('phpunit/phpunit', $requireDev, 'PHPUnit must be in require-dev');
        $this->assertStringContainsString(
            '10.5.62',
            $requireDev['phpunit/phpunit'],
            'PHPUnit must be >= 10.5.62 to address CVE-2026-24765'
        );
    }

    /**
     * Test workflow structure: top-level jobs, permissions, DAG, and digest subject.
     * Parses YAML line-by-line bounded by job names to avoid cross-job interference.
     */
    public function testWorkflowJobStructure(): void
    {
        $workflowPath = __DIR__ . '/../../.github/workflows/build-container.yml';
        $yaml = file_get_contents($workflowPath);
        $this->assertIsString($yaml);

        // Parse jobs into individual text blocks (bounded parser)
        $jobs = $this->parseWorkflowJobs($yaml);

        $this->assertNotEmpty($jobs, 'No jobs found in workflow');

        // Required top-level jobs
        $requiredJobs = ['phpstan', 'composer-audit', 'build-and-push', 'sign', 'sbom', 'sync-readme', 'summary'];
        foreach ($requiredJobs as $jobName) {
            $this->assertArrayHasKey($jobName, $jobs, "Missing required top-level job: $jobName");
        }

        // Check sync-readme is top-level (not nested under another job)
        $this->assertArrayHasKey('sync-readme', $jobs, 'sync-readme must exist as a parsed top-level job');

        // Verify read-only job permissions (bounded to each job)
        $readOnlyJobs = ['phpstan', 'composer-audit'];
        foreach ($readOnlyJobs as $jobName) {
            $jobBlock = $jobs[$jobName];
            $this->assertStringContainsString(
                'permissions:',
                $jobBlock,
                "Job '$jobName' must declare permissions"
            );
            $this->assertMatchesRegularExpression(
                '/^\s+permissions:\s*\n\s+contents:\s*read/m',
                $jobBlock,
                "Job '$jobName' permissions must be 'contents: read'"
            );
        }

        // Verify build, sign, sbom dependency order
        $this->assertArrayHasKey('sign', $jobs);
        $this->assertStringContainsString(
            'needs: [build-and-push]',
            $jobs['sign'],
            'sign job must depend on build-and-push'
        );

        $this->assertArrayHasKey('sbom', $jobs);
        $this->assertStringContainsString(
            'needs: [build-and-push]',
            $jobs['sbom'],
            'sbom job must depend on build-and-push'
        );

        // Verify SBOM uses exact immutable digest subject
        $this->assertStringContainsString(
            '${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}@${{ needs.build-and-push.outputs.digest }}',
            $jobs['sbom'],
            'SBOM image must use exact immutable digest subject (not mutable tag)'
        );

        // Summary job must depend on all critical jobs
        $this->assertStringContainsString(
            'needs: [build-and-push, sign, sbom, sync-readme, phpstan, composer-audit]',
            $jobs['summary'],
            'summary job must depend on all critical jobs in correct order'
        );

        $this->assertTrue(true, 'All workflow structure assertions passed');
    }

    /**
     * Parse workflow YAML into per-job text blocks.
     * Bounded parser: stops at next job definition to avoid cross-job interference.
     *
     * @return array<string, string> Map of job name to job YAML block
     */
    private function parseWorkflowJobs(string $yaml): array
    {
        $jobs = [];
        $lines = explode("\n", $yaml);
        $currentJob = null;
        $currentJobStart = -1;
        $inJobsSection = false;

        foreach ($lines as $lineNum => $line) {
            // Detect start of jobs section
            if (preg_match('/^jobs:\s*$/', $line)) {
                $inJobsSection = true;
                continue;
            }

            if (!$inJobsSection) {
                continue;
            }

            // Detect top-level job (2-space indent, ends with colon)
            if (preg_match('/^  ([a-z\-]+):\s*$/', $line, $matches)) {
                // Save previous job if any
                if ($currentJob !== null && $currentJobStart >= 0) {
                    $jobContent = implode("\n", array_slice($lines, $currentJobStart, $lineNum - $currentJobStart));
                    $jobs[$currentJob] = $jobContent;
                }

                // Start new job
                $currentJob = $matches[1];
                $currentJobStart = $lineNum;
            }
        }

        // Save last job
        if ($currentJob !== null && $currentJobStart >= 0) {
            $jobContent = implode("\n", array_slice($lines, $currentJobStart));
            $jobs[$currentJob] = $jobContent;
        }

        return $jobs;
    }
}
