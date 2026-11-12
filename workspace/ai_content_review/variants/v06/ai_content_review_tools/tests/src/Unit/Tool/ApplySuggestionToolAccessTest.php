<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_review\Entity\InternalReviewRecordInterface;
use Drupal\ai_content_review\Entity\SuggestionInterface;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\ApplySuggestionTool;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access control for the Apply Suggestion tool.
 *
 * Approved access: the "run reviews" permission plus update access on the
 * reviewed content, as the module's own triage and apply endpoints require.
 */
#[CoversClass(ApplySuggestionTool::class)]
#[Group('ai_content_review_tools')]
final class ApplySuggestionToolAccessTest extends UnitTestCase {

  use ToolAccessTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAccessContainer();
  }

  /**
   * Runs checkAccess() with the helper returning the given lookup.
   */
  private function check(array $lookup, AccountInterface $account): bool {
    $helper = $this->createMock(ReviewToolsHelper::class);
    $helper->method('loadSuggestion')->willReturn($lookup);
    $tool = $this->buildTool(ApplySuggestionTool::class, $account);
    (new \ReflectionProperty($tool, 'helper'))->setValue($tool, $helper);
    return $this->invokeCheckAccess($tool, ['suggestion_id' => 5], $account);
  }

  /**
   * A suggestion on content the account may or may not edit.
   */
  private function lookup(bool $may_edit): array {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')->with('update')->willReturn($may_edit ? AccessResult::allowed() : AccessResult::forbidden());
    return [
      $this->createMock(SuggestionInterface::class),
      $this->createMock(InternalReviewRecordInterface::class),
      $entity,
    ];
  }

  /**
   * The tool declares the approved permission.
   */
  public function testDeclaresApprovedPermission(): void {
    $this->assertSame('run reviews', $this->definitionOf(ApplySuggestionTool::class)->getPermission());
    $this->assertTrue($this->permissionAllows(ApplySuggestionTool::class, $this->accountWith(['run reviews'])));
  }

  /**
   * Denied without "run reviews", whatever else the account may do.
   */
  public function testDeniedWithoutPermission(): void {
    $account = $this->accountWith(['view review records', 'configure reviews', 'run batch reviews']);
    $this->assertFalse($this->permissionAllows(ApplySuggestionTool::class, $account));
  }

  /**
   * Allowed when the account may edit the reviewed content.
   */
  public function testAllowedWhenContentIsEditable(): void {
    $this->assertTrue($this->check($this->lookup(TRUE), $this->accountWith(['run reviews'])));
  }

  /**
   * Denied when the account may not edit the reviewed content.
   */
  public function testDeniedWhenContentIsNotEditable(): void {
    $this->assertFalse($this->check($this->lookup(FALSE), $this->accountWith(['run reviews'])));
  }

  /**
   * An unknown suggestion is left to doExecute(), which explains the error.
   */
  public function testUnknownSuggestionIsLeftToExecute(): void {
    $this->assertTrue($this->check([NULL, NULL, NULL], $this->accountWith(['run reviews'])));
  }

}
