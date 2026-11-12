<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\ListReviewResultsTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access control for the List Review Results tool.
 */
#[CoversClass(ListReviewResultsTool::class)]
#[Group('ai_content_review_tools')]
final class ListReviewResultsToolAccessTest extends UnitTestCase {

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
    $this->assertSame('view review records', $this->definitionOf(ListReviewResultsTool::class)->getPermission());
  }

  /**
   * Allowed with the permission; checkAccess() adds no further condition.
   */
  public function testAllowedWithPermission(): void {
    $account = $this->accountWith(['view review records']);
    $this->assertTrue($this->permissionAllows(ListReviewResultsTool::class, $account));
    $this->assertTrue($this->invokeCheckAccess($this->buildTool(ListReviewResultsTool::class, $account), [], $account));
  }

  /**
   * Denied without it, even with the module's other permissions.
   */
  public function testDeniedWithoutPermission(): void {
    $account = $this->accountWith(['run reviews', 'configure reviews', 'view review history', 'access content']);
    $this->assertFalse($this->permissionAllows(ListReviewResultsTool::class, $account));
  }

}
