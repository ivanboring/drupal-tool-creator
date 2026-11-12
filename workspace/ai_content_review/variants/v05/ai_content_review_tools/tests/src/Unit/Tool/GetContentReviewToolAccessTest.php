<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\GetContentReviewTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access control for the Get Content Review tool.
 */
#[CoversClass(GetContentReviewTool::class)]
#[Group('ai_content_review_tools')]
final class GetContentReviewToolAccessTest extends UnitTestCase {

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
    $this->assertSame('view review records', $this->definitionOf(GetContentReviewTool::class)->getPermission());
  }

  /**
   * Allowed with the permission; checkAccess() adds no further condition.
   */
  public function testAllowedWithPermission(): void {
    $account = $this->accountWith(['view review records']);
    $this->assertTrue($this->permissionAllows(GetContentReviewTool::class, $account));
    $this->assertTrue($this->invokeCheckAccess($this->buildTool(GetContentReviewTool::class, $account), [], $account));
  }

  /**
   * Denied without it, even with the module's other permissions.
   */
  public function testDeniedWithoutPermission(): void {
    $account = $this->accountWith(['run reviews', 'configure reviews', 'view review history', 'access content']);
    $this->assertFalse($this->permissionAllows(GetContentReviewTool::class, $account));
  }

}
