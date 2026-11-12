<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\ListReviewResultsTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the List Review Results tool.
 */
#[CoversClass(ListReviewResultsTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class ListReviewResultsToolTest extends ReviewToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $rule = $this->createArticleRule();
    [$tone, $inclusive] = $rule->getCriteria();
    $this->createRecord($this->createArticle('Summer sale'), $tone, 55);
    // An old failing review superseded by a passing one.
    $team = $this->createArticle('Meet the team');
    $this->createRecord($team, $tone, 30);
    $this->createRecord($team, $tone, 92);
    $this->createRecord($team, $inclusive, 50);
    $this->createRecord($this->createArticle('Privacy update'), $tone, 70);
    $this->createArticle('Opening times');
    $this->createRecord($this->createArticle('Hidden draft', FALSE), $tone, 10);
    $this->createArticle('About us', TRUE, 'page');
  }

  /**
   * Grades the latest record per criterion against the thresholds.
   */
  public function testSuccessfulExecution(): void {
    $this->actAs(['view review records', 'access content']);

    $tool = $this->runTool('list_review_results', ['criterion' => 'Tone & voice', 'grade' => 'fail']);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame(['Summer sale'], array_column($tool->getOutputValue('results'), 'title'));
    $this->assertSame(1, $tool->getOutputValue('total'));
    // Meet the team passes now; Privacy update warns; Opening times is new.
    // The unpublished draft is not visible to this user at all.
    $this->assertSame(['pass' => 1, 'warn' => 1, 'fail' => 1, 'not reviewed' => 1], $tool->getOutputValue('counts'));
    $this->assertStringContainsString('"Summer sale" (article 1): Tone & voice = 55 -> fail (pass 80, warn 60)', $this->message($tool));
  }

  /**
   * Without filters every covered item and criterion is listed, pages not.
   */
  public function testAllCriteria(): void {
    $this->actAs(['view review records', 'access content', 'bypass node access']);

    $tool = $this->runTool('list_review_results', []);

    $rows = $tool->getOutputValue('results');
    $this->assertSame(10, $tool->getOutputValue('total'));
    $this->assertNotContains('About us', array_column($rows, 'title'));
    $team = array_values(array_filter($rows, static fn($r) => $r['title'] === 'Meet the team'));
    $this->assertSame([92, 50], array_column($team, 'score'));
    $this->assertSame(['pass', 'fail'], array_column($team, 'grade'));
  }

  /**
   * An unknown criterion is refused with the configured ones listed.
   */
  public function testUnknownCriterionFails(): void {
    $this->actAs(['view review records', 'access content']);

    $tool = $this->runTool('list_review_results', ['criterion' => 'Spelling']);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('"Tone & voice", "Inclusive language"', $this->message($tool));
  }

  /**
   * An invalid grade is rejected by the input definition.
   */
  public function testInvalidGradeFails(): void {
    $this->actAs(['view review records', 'access content']);
    $tool = $this->runTool('list_review_results', ['grade' => 'terrible']);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
