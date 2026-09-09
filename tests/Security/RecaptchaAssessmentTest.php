<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/functions.php';

final class RecaptchaAssessmentTest extends TestCase
{
    public static function assessmentProvider(): array
    {
        return [
            'accepts a successful login assessment at the score threshold' => [
                ['success' => true, 'action' => 'login', 'score' => 0.5],
                true,
            ],
            'rejects a failed assessment' => [
                ['success' => false, 'action' => 'login', 'score' => 0.9],
                false,
            ],
            'rejects an assessment for another action' => [
                ['success' => true, 'action' => 'signup', 'score' => 0.9],
                false,
            ],
            'rejects an assessment without an action' => [
                ['success' => true, 'score' => 0.9],
                false,
            ],
            'rejects an assessment without a score' => [
                ['success' => true, 'action' => 'login'],
                false,
            ],
            'rejects an assessment with a non-numeric score' => [
                ['success' => true, 'action' => 'login', 'score' => 'high'],
                false,
            ],
            'rejects a login assessment below the score threshold' => [
                ['success' => true, 'action' => 'login', 'score' => 0.49],
                false,
            ],
        ];
    }

    #[DataProvider('assessmentProvider')]
    public function testValidatesRecaptchaLoginAssessment(array $assessment, bool $expected): void
    {
        self::assertSame($expected, isValidRecaptchaLoginAssessment($assessment));
    }
}
