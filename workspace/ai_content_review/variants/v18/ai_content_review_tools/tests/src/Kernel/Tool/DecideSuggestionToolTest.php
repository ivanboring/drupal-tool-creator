<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review\Entity\SuggestionInterface;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\DecideSuggestionTool;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Decide Suggestion tool.
 */
#[CoversClass(DecideSuggestionTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class DecideSuggestionToolTest extends ReviewToolsKernelTestBase {

  /**
   * The reviewed article.
   */
  private NodeInterface $node;

  /**
   * The Tone & voice criterion.
   */
  private array $tone;

  /**
   * A text suggestion on the article's latest review.
   */
  private SuggestionInterface $suggestion;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tone = $this->createArticleRule()->getCriteria()[0];
    $this->node = $this->createArticle('Summer sale');
    $record = $this->createRecord($this->node, $this->tone, 55);
    $this->suggestion = $this->createSuggestion($record, 'text_replace', 'title', 'Summer sale', 'Our summer sale is here');
  }

  /**
   * Records the decision and who made it, without touching the content.
   */
  public function testSuccessfulExecution(): void {
    $user = $this->actAs(['run reviews', 'edit any article content', 'access content']);

    $tool = $this->runTool('decide_suggestion', [
      'suggestion_id' => (int) $this->suggestion->id(),
      'decision' => 'accepted',
    ]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame('accepted', $tool->getOutputValue('decision'));
    $this->assertStringContainsString('is now accepted and on the improvement plan.', $this->message($tool));
    $this->assertStringContainsString('ai_content_review_tools_apply_suggestion', $this->message($tool));
    $suggestion = $this->container->get('entity_type.manager')->getStorage('suggestion')->loadUnchanged($this->suggestion->id());
    $this->assertSame('accepted', $suggestion->getDecision());
    $this->assertSame((int) $user->id(), $suggestion->getDecidedByUid());
    $this->assertFalse($suggestion->isApplied());
    $this->assertSame('Summer sale', $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id())->label());

    // Deciding the same again says so instead of repeating the first answer.
    $tool = $this->runTool('decide_suggestion', [
      'suggestion_id' => (int) $this->suggestion->id(),
      'decision' => 'accepted',
    ]);
    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertStringContainsString('was already accepted; nothing changed', $this->message($tool));
  }

  /**
   * A suggestion from a superseded review cannot be decided.
   */
  public function testSupersededSuggestionFails(): void {
    $this->actAs(['run reviews', 'edit any article content', 'access content']);
    $this->createRecord($this->node, $this->tone, 90);

    $tool = $this->runTool('decide_suggestion', [
      'suggestion_id' => (int) $this->suggestion->id(),
      'decision' => 'ignored',
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('no longer current', $this->message($tool));
  }

  /**
   * An unknown id fails with a pointer to the tool that lists ids.
   */
  public function testUnknownSuggestionFails(): void {
    $this->actAs(['run reviews', 'edit any article content', 'access content']);

    $tool = $this->runTool('decide_suggestion', ['suggestion_id' => 999, 'decision' => 'ignored']);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('ai_content_review_tools_get_content_review', $this->message($tool));
  }

  /**
   * Access needs edit access on the reviewed content, not just the permission.
   */
  public function testAccessNeedsEditAccess(): void {
    $this->actAs(['run reviews', 'access content']);
    $tool = $this->toolManager->createInstance('ai_content_review_tools_decide_suggestion');
    $tool->setInputValue('suggestion_id', (int) $this->suggestion->id());
    $tool->setInputValue('decision', 'accepted');
    $this->assertFalse($tool->access());

    $this->actAs(['run reviews', 'edit any article content', 'access content']);
    $tool = $this->toolManager->createInstance('ai_content_review_tools_decide_suggestion');
    $tool->setInputValue('suggestion_id', (int) $this->suggestion->id());
    $tool->setInputValue('decision', 'accepted');
    $this->assertTrue($tool->access());
  }

  /**
   * An unknown decision is rejected by the input definition.
   */
  public function testInvalidDecisionFails(): void {
    $this->actAs(['run reviews', 'edit any article content', 'access content']);
    $tool = $this->runTool('decide_suggestion', [
      'suggestion_id' => (int) $this->suggestion->id(),
      'decision' => 'maybe',
    ]);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
