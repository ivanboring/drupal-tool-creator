<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_content_review\Entity\InternalReviewRecordInterface;
use Drupal\ai_content_review\Entity\SuggestionInterface;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\DecideSuggestionTool;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access control for the Decide Suggestion tool.
 *
 * Approved access: the "run reviews" permission plus update access on the
 * reviewed content of every suggestion in the call, as the module's own
 * triage and apply endpoints require; one suggestion on content the account
 * may not edit denies the whole call.
 */
#[CoversClass(DecideSuggestionTool::class)]
#[Group('ai_content_review_tools')]
final class DecideSuggestionToolAccessTest extends UnitTestCase {

  use ToolAccessTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAccessContainer();
  }

  /**
   * Runs checkAccess() with the helper returning the given lookups by id.
   *
   * @param array<int, array> $lookups
   *   The loadSuggestion() result per suggestion id; other ids are unknown.
   * @param array $values
   *   The tool input values.
   */
  private function check(array $lookups, array $values, AccountInterface $account): bool {
    $helper = $this->createMock(ReviewToolsHelper::class);
    $helper->method('loadSuggestion')->willReturnCallback(
      static fn(int $id): array => $lookups[$id] ?? [NULL, NULL, NULL],
    );
    $tool = $this->buildTool(DecideSuggestionTool::class, $account);
    (new \ReflectionProperty($tool, 'helper'))->setValue($tool, $helper);
    return $this->invokeCheckAccess($tool, $values, $account);
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
    $this->assertSame('run reviews', $this->definitionOf(DecideSuggestionTool::class)->getPermission());
    $this->assertTrue($this->permissionAllows(DecideSuggestionTool::class, $this->accountWith(['run reviews'])));
  }

  /**
   * Denied without "run reviews", whatever else the account may do.
   */
  public function testDeniedWithoutPermission(): void {
    $account = $this->accountWith(['view review records', 'configure reviews', 'run batch reviews']);
    $this->assertFalse($this->permissionAllows(DecideSuggestionTool::class, $account));
  }

  /**
   * Allowed when the account may edit the content of every suggestion.
   */
  public function testAllowedWhenEveryContentIsEditable(): void {
    $lookups = [5 => $this->lookup(TRUE), 6 => $this->lookup(TRUE)];
    $this->assertTrue($this->check($lookups, ['decisions' => [['suggestion_id' => 5, 'decision' => 'accepted'], ['suggestion_id' => 6, 'decision' => 'ignored']]], $this->accountWith(['run reviews'])));
  }

  /**
   * Denied when the account may not edit the content of one suggestion.
   */
  public function testDeniedWhenOneContentIsNotEditable(): void {
    $lookups = [5 => $this->lookup(TRUE), 6 => $this->lookup(FALSE)];
    $account = $this->accountWith(['run reviews']);
    $this->assertFalse($this->check($lookups, ['decisions' => [['suggestion_id' => 5, 'decision' => 'accepted'], ['suggestion_id' => 6, 'decision' => 'ignored']]], $account));
    $this->assertFalse($this->check($lookups, ['decisions' => [['suggestion_id' => 6, 'decision' => 'undecided']]], $account));
    $this->assertTrue($this->check($lookups, ['decisions' => [['suggestion_id' => 5, 'decision' => 'accepted']]], $account));
  }

  /**
   * Unknown suggestions and an empty call are left to doExecute(), which explains the error.
   */
  public function testUnknownSuggestionIsLeftToExecute(): void {
    $account = $this->accountWith(['run reviews']);
    $this->assertTrue($this->check([], ['decisions' => [['suggestion_id' => 5, 'decision' => 'accepted']]], $account));
    $this->assertTrue($this->check([], [], $account));
  }

}
