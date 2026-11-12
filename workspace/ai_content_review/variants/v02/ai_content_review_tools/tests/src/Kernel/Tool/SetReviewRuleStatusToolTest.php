<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\SetReviewRuleStatusTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Set Review Rule Status tool.
 */
#[CoversClass(SetReviewRuleStatusTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class SetReviewRuleStatusToolTest extends ReviewToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createArticleRule();
    $this->actAs(['configure reviews']);
  }

  /**
   * Disables and re-enables a rule, keeping its criteria.
   */
  public function testSuccessfulExecution(): void {
    $criteria = $this->reloadRule('article_quality')->getCriteria();

    $tool = $this->runTool('set_review_rule_status', ['rule' => 'article quality', 'enabled' => FALSE]);
    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertFalse($tool->getOutputValue('enabled'));
    $this->assertStringContainsString('is now disabled', $this->message($tool));
    $rule = $this->reloadRule('article_quality');
    $this->assertFalse($rule->status());
    $this->assertSame($criteria, $rule->getCriteria());

    $tool = $this->runTool('set_review_rule_status', ['rule' => 'article_quality', 'enabled' => TRUE]);
    $this->assertTrue($tool->getResult()->isSuccess());
    $this->assertTrue($this->reloadRule('article_quality')->status());
  }

  /**
   * Asking for the state a rule is already in changes nothing.
   */
  public function testAlreadyInStateIsReported(): void {
    $tool = $this->runTool('set_review_rule_status', ['rule' => 'Article quality', 'enabled' => TRUE]);
    $this->assertTrue($tool->getResult()->isSuccess());
    $this->assertStringContainsString('already enabled', $this->message($tool));
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $tool = $this->runTool('set_review_rule_status', ['rule' => 'Article quality']);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertTrue($this->reloadRule('article_quality')->status());
  }

}
