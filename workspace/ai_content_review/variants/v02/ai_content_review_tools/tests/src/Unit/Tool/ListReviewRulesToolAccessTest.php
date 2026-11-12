<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\ListReviewRulesTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access control for the List Review Rules tool.
 */
#[CoversClass(ListReviewRulesTool::class)]
#[Group('ai_content_review_tools')]
final class ListReviewRulesToolAccessTest extends UnitTestCase {

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
    $this->assertSame('configure reviews+view review records', $this->definitionOf(ListReviewRulesTool::class)->getPermission());
  }

  /**
   * Allowed with the permission; checkAccess() adds no further condition.
   */
  public function testAllowedWithPermission(): void {
    $account = $this->accountWith(['configure reviews']);
    $this->assertTrue($this->permissionAllows(ListReviewRulesTool::class, $account));
    $this->assertTrue($this->invokeCheckAccess($this->buildTool(ListReviewRulesTool::class, $account), [], $account));
    $account = $this->accountWith(['view review records']);
    $this->assertTrue($this->permissionAllows(ListReviewRulesTool::class, $account));
    $this->assertTrue($this->invokeCheckAccess($this->buildTool(ListReviewRulesTool::class, $account), [], $account));
  }

  /**
   * Denied without it, even with the module's other permissions.
   */
  public function testDeniedWithoutPermission(): void {
    $account = $this->accountWith(['run reviews', 'view review history', 'run batch reviews', 'access content']);
    $this->assertFalse($this->permissionAllows(ListReviewRulesTool::class, $account));
  }

}
