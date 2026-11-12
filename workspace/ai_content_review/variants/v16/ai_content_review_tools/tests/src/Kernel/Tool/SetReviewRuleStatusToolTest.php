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

    $tool = $this->runTool('enable_or_disable_review_rule', ['rule' => 'article quality', 'enabled' => FALSE]);
    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertFalse($tool->getOutputValue('enabled'));
    $this->assertStringContainsString('is now disabled', $this->message($tool));
    $rule = $this->reloadRule('article_quality');
    $this->assertFalse($rule->status());
    $this->assertSame($criteria, $rule->getCriteria());

    $tool = $this->runTool('enable_or_disable_review_rule', ['rule' => 'article_quality', 'enabled' => TRUE]);
    $this->assertTrue($tool->getResult()->isSuccess());
    $this->assertTrue($this->reloadRule('article_quality')->status());
  }

  /**
   * Asking for the state a rule is already in changes nothing.
   */
  public function testAlreadyInStateIsReported(): void {
    $tool = $this->runTool('enable_or_disable_review_rule', ['rule' => 'Article quality', 'enabled' => TRUE]);
    $this->assertTrue($tool->getResult()->isSuccess());
    $this->assertStringContainsString('already enabled', $this->message($tool));
  }

  /**
   * A near-miss rule name fails, names the closest rule and changes nothing.
   */
  public function testNearMissRuleNamesClosestMatch(): void {
    $tool = $this->runTool('enable_or_disable_review_rule', ['rule' => 'Quality of articles', 'enabled' => FALSE]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('Closest match: "Article quality" (article_quality). If that is the rule the user means, call the tool again with it.', $this->message($tool));
    $this->assertTrue($this->reloadRule('article_quality')->status());
  }

  /**
   * Generic nouns are dropped: "Article review" finds "Article quality".
   */
  public function testGenericNounsAreIgnored(): void {
    $tool = $this->runTool('enable_or_disable_review_rule', ['rule' => 'Article review', 'enabled' => FALSE]);
    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertStringContainsString('Rule "Article quality" (id article_quality) is now disabled.', $this->message($tool));
    $this->assertFalse($this->reloadRule('article_quality')->status());
  }

  /**
   * Without the generic nouns, a name must still match one rule only.
   */
  public function testGenericNounsDoNotGuessBetweenRules(): void {
    $values = ['id' => 'article_length', 'label' => 'Article length'] + $this->reloadRule('article_quality')->toArray();
    unset($values['uuid']);
    $this->container->get('entity_type.manager')->getStorage('review_rule')->create($values)->save();

    foreach (['Article review', 'the review rule'] as $ref) {
      $tool = $this->runTool('enable_or_disable_review_rule', ['rule' => $ref, 'enabled' => FALSE]);
      $this->assertFalse($tool->getResult()->isSuccess(), $ref);
      $this->assertStringContainsString(sprintf('No review rule matches "%s".', $ref), $this->message($tool));
    }
    $this->assertTrue($this->reloadRule('article_quality')->status());
    $this->assertTrue($this->reloadRule('article_length')->status());
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $tool = $this->runTool('enable_or_disable_review_rule', ['rule' => 'Article quality']);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertTrue($this->reloadRule('article_quality')->status());
  }

}
