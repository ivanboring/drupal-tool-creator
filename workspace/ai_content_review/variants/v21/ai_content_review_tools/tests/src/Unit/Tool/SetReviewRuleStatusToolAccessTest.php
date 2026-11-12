<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\SetReviewRuleStatusTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access control for the Set Review Rule Status tool.
 */
#[CoversClass(SetReviewRuleStatusTool::class)]
#[Group('ai_content_review_tools')]
final class SetReviewRuleStatusToolAccessTest extends UnitTestCase {

  use ToolAccessTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAccessContainer();
  }

  /**
   * The tool declares the permission the user approved.
   */
  public function testDeclaresApprovedPermission(): void {
    $this->assertSame('configure reviews', $this->definitionOf(SetReviewRuleStatusTool::class)->getPermission());
  }

  /**
   * Allowed with the permission; checkAccess() adds no further condition.
   */
  public function testAllowedWithPermission(): void {
    $account = $this->accountWith(['configure reviews']);
    $this->assertTrue($this->permissionAllows(SetReviewRuleStatusTool::class, $account));
    $this->assertTrue($this->invokeCheckAccess($this->buildTool(SetReviewRuleStatusTool::class, $account), [], $account));
  }

  /**
   * Denied without it, even with the module's other permissions.
   */
  public function testDeniedWithoutPermission(): void {
    $account = $this->accountWith([
      'run reviews',
      'view review records',
      'view review history',
      'run batch reviews',
      'view review queue',
      'access content',
    ]);
    $this->assertFalse($this->permissionAllows(SetReviewRuleStatusTool::class, $account));
  }

}
