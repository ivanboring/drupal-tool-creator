<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\QueueReviewsTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Queue Reviews tool.
 */
#[CoversClass(QueueReviewsTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class QueueReviewsToolTest extends ReviewToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createArticleRule();
    $this->createArticle('Summer sale');
    $this->createArticle('Meet the team');
    $this->createArticle('Draft', FALSE);
    $this->createArticle('About us', TRUE, 'page');
    $this->actAs(['run batch reviews', 'access content', 'bypass node access']);
  }

  /**
   * Queues one pending review per published article per criterion.
   */
  public function testSuccessfulExecution(): void {
    $tool = $this->runTool('queue_reviews', ['rule' => 'Article quality']);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame(4, $tool->getOutputValue('queued'));
    $this->assertSame(2, $tool->getOutputValue('items'));
    $this->assertSame(4, \Drupal::queue('ai_content_review_batch_review')->numberOfItems());
    $records = $this->container->get('entity_type.manager')->getStorage('review_record')->loadMultiple();
    $this->assertCount(4, $records);
    $this->assertSame(['pending'], array_values(array_unique(array_map(static fn($r) => $r->getStatus(), $records))));
    $this->assertStringContainsString('spend AI provider budget', $this->message($tool));

    // Queueing again skips what is already waiting.
    $tool = $this->runTool('queue_reviews', ['rule' => 'article_quality']);
    $this->assertSame(0, $tool->getOutputValue('queued'));
    $this->assertSame(4, $tool->getOutputValue('skipped'));
  }

  /**
   * A disabled rule cannot be queued; the message says how to enable it.
   */
  public function testDisabledRuleFails(): void {
    $this->reloadRule('article_quality')->disable()->save();

    $tool = $this->runTool('queue_reviews', ['rule' => 'Article quality']);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('ai_content_review_tools_enable_or_disable_review_rule', $this->message($tool));
    $this->assertSame(0, \Drupal::queue('ai_content_review_batch_review')->numberOfItems());
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $tool = $this->runTool('queue_reviews', []);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
