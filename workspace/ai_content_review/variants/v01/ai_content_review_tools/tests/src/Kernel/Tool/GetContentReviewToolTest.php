<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\GetContentReviewTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Get Content Review tool.
 */
#[CoversClass(GetContentReviewTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class GetContentReviewToolTest extends ReviewToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $tone = $this->createArticleRule()->getCriteria()[0];
    $record = $this->createRecord($this->createArticle('Summer sale'), $tone, 55);
    $this->createSuggestion($record, 'text_replace', 'title', 'Summer sale', 'Our summer sale is here');
    $this->createSuggestion($record, 'general_recommendation', NULL, NULL, 'Add a closing line.');
    $this->createArticle('About us', TRUE, 'page');
    $this->actAs(['view review records', 'access content']);
  }

  /**
   * Shows each criterion's result with its suggestions and their ids.
   */
  public function testSuccessfulExecution(): void {
    $tool = $this->runTool('get_content_review', ['content' => 'summer']);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame('Summer sale', $tool->getOutputValue('content')['title']);
    $criteria = $tool->getOutputValue('criteria');
    $this->assertCount(2, $criteria);
    $this->assertSame('fail', $criteria[0]['grade']);
    $this->assertSame('not reviewed', $criteria[1]['grade']);
    $suggestions = $criteria[0]['suggestions'];
    $this->assertSame(['title', NULL], array_column($suggestions, 'field'));
    $this->assertSame([TRUE, FALSE], array_column($suggestions, 'can_apply'));
    $message = $this->message($tool);
    $this->assertStringContainsString('Suggestion id 1 [title]: "Summer sale" -> "Our summer sale is here"', $message);
    $this->assertStringContainsString('cannot be applied (general recommendation)', $message);
    $this->assertStringContainsString('Inclusive language (rule "Article quality"): not reviewed yet.', $message);
  }

  /**
   * Content no rule covers is reported, not treated as an error.
   */
  public function testUncoveredContent(): void {
    $tool = $this->runTool('get_content_review', ['content' => 'About us']);
    $this->assertTrue($tool->getResult()->isSuccess());
    $this->assertStringContainsString('not covered by any enabled review rule', $this->message($tool));
  }

  /**
   * Unknown content fails with a pointer to the listing tool.
   */
  public function testUnknownContentFails(): void {
    $tool = $this->runTool('get_content_review', ['content' => 'Winter sale']);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('ai_content_review_tools_list_review_results', $this->message($tool));
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $tool = $this->runTool('get_content_review', []);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
