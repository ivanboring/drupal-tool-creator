<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review\Entity\ReviewRecordInterface;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\ApplySuggestionTool;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Apply Suggestion tool.
 */
#[CoversClass(ApplySuggestionTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class ApplySuggestionToolTest extends ReviewToolsKernelTestBase {

  /**
   * The reviewed article.
   */
  private NodeInterface $node;

  /**
   * Its latest Tone & voice review.
   */
  private ReviewRecordInterface $record;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $tone = $this->createArticleRule()->getCriteria()[0];
    $this->node = $this->createArticle('Summer sale');
    $this->record = $this->createRecord($this->node, $tone, 55);
  }

  /**
   * Writes the suggested value, saves the content, marks it applied.
   */
  public function testSuccessfulExecution(): void {
    $suggestion = $this->createSuggestion($this->record, 'text_replace', 'title', 'Summer sale', 'Our summer sale is here');
    $user = $this->actAs(['run reviews', 'edit any article content', 'access content']);

    $tool = $this->runTool('apply_suggestion', ['suggestion_id' => (int) $suggestion->id()]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame('title', $tool->getOutputValue('field'));
    $this->assertStringContainsString('Applied suggestion 1 to "Summer sale"', $this->message($tool));
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame('Our summer sale is here', $storage->loadUnchanged($this->node->id())->label());
    $suggestion = $this->container->get('entity_type.manager')->getStorage('suggestion')->loadUnchanged($suggestion->id());
    $this->assertTrue($suggestion->isApplied());
    $this->assertSame((int) $user->id(), $suggestion->getAppliedByUid());

    // A second apply is refused.
    $tool = $this->runTool('apply_suggestion', ['suggestion_id' => (int) $suggestion->id()]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('already applied', $this->message($tool));
  }

  /**
   * A general recommendation has no field and cannot be applied.
   */
  public function testGeneralRecommendationFails(): void {
    $suggestion = $this->createSuggestion($this->record, 'general_recommendation', NULL, NULL, 'Add a closing line.');
    $this->actAs(['run reviews', 'edit any article content', 'access content']);

    $tool = $this->runTool('apply_suggestion', ['suggestion_id' => (int) $suggestion->id()]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('ai_content_review_tools_accept_or_ignore_suggestion', $this->message($tool));
  }

  /**
   * Access needs edit access on the reviewed content.
   */
  public function testAccessNeedsEditAccess(): void {
    $suggestion = $this->createSuggestion($this->record, 'text_replace', 'title', 'Summer sale', 'Our summer sale is here');
    $this->actAs(['run reviews', 'access content']);
    $tool = $this->toolManager->createInstance('ai_content_review_tools_apply_suggestion');
    $tool->setInputValue('suggestion_id', (int) $suggestion->id());
    $this->assertFalse($tool->access());
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $this->actAs(['run reviews', 'edit any article content', 'access content']);
    $tool = $this->runTool('apply_suggestion', []);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
