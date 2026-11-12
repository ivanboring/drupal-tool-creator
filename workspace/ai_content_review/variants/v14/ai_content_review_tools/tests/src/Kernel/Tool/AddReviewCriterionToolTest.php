<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\AddReviewCriterionTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Add Review Criterion tool.
 */
#[CoversClass(AddReviewCriterionTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class AddReviewCriterionToolTest extends ReviewToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createArticleRule();
    $this->actAs(['configure reviews']);
  }

  /**
   * Appends a criterion and leaves the existing ones exactly as they were.
   */
  public function testSuccessfulExecution(): void {
    $before = $this->reloadRule('article_quality')->getCriteria();

    $tool = $this->runTool('add_review_criterion', [
      'rule' => 'Article quality',
      'criterion_name' => 'Readability',
      'agent' => 'Editorial reviewer',
      'guidelines' => 'Short sentences.',
      'pass_score' => 70,
    ]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame(3, $tool->getOutputValue('criterion_position'));
    $criteria = $this->reloadRule('article_quality')->getCriteria();
    $this->assertCount(3, $criteria);
    $this->assertSame($before[0], $criteria[0]);
    $this->assertSame($before[1], $criteria[1]);
    $this->assertSame('Readability', $criteria[2]['label']);
    $this->assertSame(70, $criteria[2]['pass_threshold']);
    $this->assertNull($criteria[2]['warn_threshold']);
    $this->assertStringContainsString('3. "Readability"', $this->message($tool));
  }

  /**
   * A criterion name the rule already has is refused.
   */
  public function testDuplicateCriterionFails(): void {
    $tool = $this->runTool('add_review_criterion', [
      'rule' => 'article_quality',
      'criterion_name' => 'tone & voice',
      'agent' => 'editorial_reviewer',
      'pass_score' => 70,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('ai_content_review_tools_update_review_criterion', $this->message($tool));
    $this->assertCount(2, $this->reloadRule('article_quality')->getCriteria());
  }

  /**
   * An unknown rule is refused with the existing rules listed.
   */
  public function testUnknownRuleFails(): void {
    $tool = $this->runTool('add_review_criterion', [
      'rule' => 'Page quality',
      'criterion_name' => 'Readability',
      'agent' => 'editorial_reviewer',
      'pass_score' => 70,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('"Article quality" (article_quality)', $this->message($tool));
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $tool = $this->runTool('add_review_criterion', ['rule' => 'Article quality']);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
