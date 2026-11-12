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
   * A headline suggestion on the article's latest review.
   */
  private SuggestionInterface $headline;

  /**
   * A body suggestion on the same review.
   */
  private SuggestionInterface $body;

  /**
   * A general recommendation on the same review.
   */
  private SuggestionInterface $general;

  /**
   * The permissions of an editor who may decide on article suggestions.
   */
  private const EDITOR = ['run reviews', 'edit any article content', 'access content'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tone = $this->createArticleRule()->getCriteria()[0];
    $this->node = $this->createArticle('Summer sale');
    $record = $this->createRecord($this->node, $this->tone, 55);
    $this->headline = $this->createSuggestion($record, 'text_replace', 'title', 'Summer sale', 'Our summer sale is here');
    $this->body = $this->createSuggestion($record, 'text_replace', 'body', 'Discounts can be claimed.', 'Claim your discount.');
    $this->general = $this->createSuggestion($record, 'general', NULL, NULL, 'End with a call to action.');
  }

  /**
   * The current decision of a suggestion in storage.
   */
  private function decisionOf(SuggestionInterface $suggestion): string {
    return $this->container->get('entity_type.manager')->getStorage('suggestion')->loadUnchanged($suggestion->id())->getDecision();
  }

  /**
   * One call records every decision and who made it, without touching the content.
   */
  public function testSuccessfulExecution(): void {
    $user = $this->actAs(self::EDITOR);

    $tool = $this->runTool('decide_suggestion', [
      'decisions' => [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted'], ['suggestion_id' => (int) $this->body->id(), 'decision' => 'ignored']],
    ]);

    $message = $this->message($tool);
    $this->assertTrue($tool->getResult()->isSuccess(), $message);
    $this->assertStringContainsString(sprintf('Suggestion %d on "Summer sale" ([title] "Our summer sale is here") is now accepted and on the improvement plan.', $this->headline->id()), $message);
    $this->assertStringContainsString(sprintf('Suggestion %d on "Summer sale" ([body] "Claim your discount.") is now ignored.', $this->body->id()), $message);
    $this->assertStringContainsString('ai_content_review_tools_apply_suggestion', $message);
    $this->assertSame([
      ['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted', 'content_id' => (int) $this->node->id()],
      ['suggestion_id' => (int) $this->body->id(), 'decision' => 'ignored', 'content_id' => (int) $this->node->id()],
    ], $tool->getOutputValue('decisions'));

    $headline = $this->container->get('entity_type.manager')->getStorage('suggestion')->loadUnchanged($this->headline->id());
    $this->assertSame('accepted', $headline->getDecision());
    $this->assertSame((int) $user->id(), $headline->getDecidedByUid());
    $this->assertFalse($headline->isApplied());
    $this->assertSame('ignored', $this->decisionOf($this->body));
    $this->assertSame('undecided', $this->decisionOf($this->general));
    $this->assertSame('Summer sale', $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id())->label());

    // Deciding the same again says so instead of repeating the first answer;
    // resetting a suggestion that is still open changes nothing either.
    $tool = $this->runTool('decide_suggestion', [
      'decisions' => [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted'], ['suggestion_id' => (int) $this->general->id(), 'decision' => 'undecided']],
    ]);
    $message = $this->message($tool);
    $this->assertTrue($tool->getResult()->isSuccess(), $message);
    $this->assertStringContainsString('was already accepted; nothing changed', $message);
    $this->assertStringContainsString('was already undecided; nothing changed', $message);
    $this->assertStringNotContainsString('ai_content_review_tools_apply_suggestion', $message);
  }

  /**
   * A suggestion from a superseded review fails the call and decides nothing.
   */
  public function testSupersededSuggestionFails(): void {
    $this->actAs(self::EDITOR);
    $newer = $this->createRecord($this->node, $this->tone, 90);
    $current = $this->createSuggestion($newer, 'text_replace', 'title', 'Summer sale', 'Sale time');

    $tool = $this->runTool('decide_suggestion', [
      'decisions' => [['suggestion_id' => (int) $current->id(), 'decision' => 'accepted'], ['suggestion_id' => (int) $this->body->id(), 'decision' => 'ignored']],
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('Nothing was decided', $this->message($tool));
    $this->assertStringContainsString('older review', $this->message($tool));
    $this->assertSame('undecided', $this->decisionOf($current));
    $this->assertSame('undecided', $this->decisionOf($this->body));
  }

  /**
   * An unknown id fails the call, points to the tool that lists ids and decides nothing.
   */
  public function testUnknownSuggestionFails(): void {
    $this->actAs(self::EDITOR);

    $tool = $this->runTool('decide_suggestion', [
      'decisions' => [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted'], ['suggestion_id' => 999, 'decision' => 'ignored']],
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('no suggestion with id 999', $this->message($tool));
    $this->assertStringContainsString('ai_content_review_tools_get_content_review', $this->message($tool));
    $this->assertSame('undecided', $this->decisionOf($this->headline));
  }

  /**
   * A call without ids, or with two decisions for one suggestion, fails.
   */
  public function testMissingOrConflictingDecisionsFail(): void {
    $this->actAs(self::EDITOR);

    $tool = $this->runTool('decide_suggestion', ['decisions' => []]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('No decisions given', $this->message($tool));

    $tool = $this->runTool('decide_suggestion', [
      'decisions' => [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted'], ['suggestion_id' => (int) $this->headline->id(), 'decision' => 'ignored']],
    ]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('give each suggestion one decision', $this->message($tool));
    $this->assertSame('undecided', $this->decisionOf($this->headline));
  }

  /**
   * Access needs edit access on the content of every suggestion in the call.
   */
  public function testAccessNeedsEditAccessOnEverySuggestion(): void {
    $page = $this->createArticle('About us', TRUE, 'page');
    $on_page = $this->createSuggestion($this->createRecord($page, $this->tone, 50), 'text_replace', 'title', 'About us', 'Who we are');

    $this->actAs(['run reviews', 'access content']);
    $tool = $this->toolManager->createInstance('ai_content_review_tools_decide_suggestion');
    $tool->setInputValue('decisions', [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted']]);
    $this->assertFalse($tool->access());

    $this->actAs(self::EDITOR);
    $tool = $this->toolManager->createInstance('ai_content_review_tools_decide_suggestion');
    $tool->setInputValue('decisions', [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted']]);
    $this->assertTrue($tool->access());

    // The editor may edit articles but not pages: one page suggestion denies
    // the whole call.
    $tool = $this->toolManager->createInstance('ai_content_review_tools_decide_suggestion');
    $tool->setInputValue('decisions', [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'accepted'], ['suggestion_id' => (int) $on_page->id(), 'decision' => 'ignored']]);
    $this->assertFalse($tool->access());
  }

  /**
   * An unknown decision is rejected by the input definition.
   */
  public function testInvalidDecisionFails(): void {
    $this->actAs(self::EDITOR);
    $tool = $this->runTool('decide_suggestion', ['decisions' => [['suggestion_id' => (int) $this->headline->id(), 'decision' => 'maybe']]]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame('undecided', $this->decisionOf($this->headline));
  }

}
