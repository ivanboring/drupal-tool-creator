<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\UpdateReviewCriterionTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Update Review Criterion tool.
 */
#[CoversClass(UpdateReviewCriterionTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class UpdateReviewCriterionToolTest extends ReviewToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createArticleRule();
    $this->actAs(['configure reviews']);
  }

  /**
   * A score change keeps the configuration, so earlier results still count.
   */
  public function testSuccessfulExecution(): void {
    $before = $this->reloadRule('article_quality')->getCriteria();

    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'Article quality',
      'criterion' => 'tone',
      'pass_score' => 75,
    ]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertFalse($tool->getOutputValue('results_reset'));
    $this->assertSame(['passing score 80 -> 75'], $tool->getOutputValue('changes'));
    $this->assertStringContainsString('Earlier review results still count', $this->message($tool));
    $criteria = $this->reloadRule('article_quality')->getCriteria();
    $this->assertSame(75, $criteria[0]['pass_threshold']);
    $this->assertSame(60, $criteria[0]['warn_threshold']);
    $this->assertSame($before[0]['configuration'], $criteria[0]['configuration']);
    $this->assertSame($before[1], $criteria[1]);
  }

  /**
   * New guidelines change what the AI is asked; the result says so.
   */
  public function testGuidelinesChangeResetsResults(): void {
    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'article_quality',
      'criterion' => '2',
      'guidelines' => 'Flag gendered terms.',
    ]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertTrue($tool->getOutputValue('results_reset'));
    $this->assertStringContainsString('no longer count', $this->message($tool));
    $this->assertSame('Flag gendered terms.', $this->reloadRule('article_quality')->getCriteria()[1]['configuration']['prompt_template']);
  }

  /**
   * Lowering pass below the current warn score explains how to fix it.
   */
  public function testPassBelowWarnFails(): void {
    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'Article quality',
      'criterion' => 'Tone & voice',
      'pass_score' => 55,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('pass a lower warn_score as well', $this->message($tool));
    $this->assertSame(80, $this->reloadRule('article_quality')->getCriteria()[0]['pass_threshold']);
  }

  /**
   * Nothing to change is a failure, not a silent success.
   */
  public function testNothingToChangeFails(): void {
    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'Article quality',
      'criterion' => 'Tone & voice',
      'pass_score' => 80,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('Nothing to change', $this->message($tool));
  }

  /**
   * An unknown criterion is refused with the rule's criteria listed.
   */
  public function testUnknownCriterionFails(): void {
    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'Article quality',
      'criterion' => 'SEO',
      'pass_score' => 50,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('1. "Tone & voice", 2. "Inclusive language"', $this->message($tool));
  }

  /**
   * A rule input that only repeats the criterion name counts as no rule.
   */
  public function testCriterionNameAsRuleCountsAsNoRule(): void {
    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'Tone & voice',
      'criterion' => 'tone & voice',
      'pass_score' => 75,
    ]);
    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertStringContainsString('Updated criterion "Tone & voice" in rule "Article quality": passing score 80 -> 75.', $this->message($tool));
    $this->assertSame(75, $this->reloadRule('article_quality')->getCriteria()[0]['pass_threshold']);

    // Any other rule that does not exist still fails.
    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'Page quality',
      'criterion' => 'Tone & voice',
      'pass_score' => 70,
    ]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('No review rule matches "Page quality".', $this->message($tool));
    $this->assertSame(75, $this->reloadRule('article_quality')->getCriteria()[0]['pass_threshold']);
  }

  /**
   * The criterion name as the rule must still point to one rule only.
   */
  public function testCriterionNameAsRuleNeedsOneMatch(): void {
    $values = ['id' => 'article_length', 'label' => 'Article length'] + $this->reloadRule('article_quality')->toArray();
    unset($values['uuid']);
    $this->container->get('entity_type.manager')->getStorage('review_rule')->create($values)->save();

    $tool = $this->runTool('update_review_criterion', [
      'rule' => 'Tone & voice',
      'criterion' => 'Tone & voice',
      'pass_score' => 75,
    ]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString(
      'More than one rule has a criterion matching "Tone & voice": "Article length", "Article quality". Pass the rule as well.',
      $this->message($tool),
    );
    $this->assertSame(80, $this->reloadRule('article_quality')->getCriteria()[0]['pass_threshold']);
    $this->assertSame(80, $this->reloadRule('article_length')->getCriteria()[0]['pass_threshold']);
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $tool = $this->runTool('update_review_criterion', ['pass_score' => 70]);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
