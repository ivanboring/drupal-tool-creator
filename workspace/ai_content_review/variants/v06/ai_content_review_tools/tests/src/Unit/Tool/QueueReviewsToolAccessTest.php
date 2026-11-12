<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\QueueReviewsTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access control for the Queue Reviews tool.
 */
#[CoversClass(QueueReviewsTool::class)]
#[Group('ai_content_review_tools')]
final class QueueReviewsToolAccessTest extends UnitTestCase {

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
    $this->assertSame('run batch reviews', $this->definitionOf(QueueReviewsTool::class)->getPermission());
  }

  /**
   * Allowed with the permission; checkAccess() adds no further condition.
   */
  public function testAllowedWithPermission(): void {
    $account = $this->accountWith(['run batch reviews']);
    $this->assertTrue($this->permissionAllows(QueueReviewsTool::class, $account));
    $this->assertTrue($this->invokeCheckAccess($this->buildTool(QueueReviewsTool::class, $account), [], $account));
  }

  /**
   * Denied without it, even with the module's other permissions.
   */
  public function testDeniedWithoutPermission(): void {
    $account = $this->accountWith(['configure reviews', 'run reviews', 'view review queue', 'view review records']);
    $this->assertFalse($this->permissionAllows(QueueReviewsTool::class, $account));
  }

}
