<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\ListReviewRulesTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the List Review Rules tool.
 */
#[CoversClass(ListReviewRulesTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class ListReviewRulesToolTest extends ReviewToolsKernelTestBase {

  /**
   * Lists rules with their criteria and only the agents a criterion can use.
   */
  public function testSuccessfulExecution(): void {
    $this->createArticleRule();
    $this->actAs(['view review records']);

    $tool = $this->runTool('list_review_rules', []);

    $this->assertTrue($tool->getResult()->isSuccess());
    $message = $this->message($tool);
    $this->assertStringContainsString('"Article quality" (id article_quality, enabled)', $message);
    $this->assertStringContainsString('"Tone & voice" (pass 80, warn 60, agent editorial_reviewer)', $message);
    $this->assertStringContainsString('"Editorial reviewer" (editorial_reviewer)', $message);
    $this->assertStringNotContainsString('chatty_helper', $message);

    $rules = $tool->getOutputValue('rules');
    $this->assertCount(1, $rules);
    $this->assertSame(['article'], $rules[0]['content_types']);
    $this->assertSame('Inclusive language', $rules[0]['criteria'][1]['name']);
    $this->assertSame(85, $rules[0]['criteria'][1]['pass_score']);
    $this->assertNull($rules[0]['criteria'][1]['warn_score']);
    $this->assertSame([['id' => 'editorial_reviewer', 'label' => 'Editorial reviewer']], $tool->getOutputValue('agents'));
  }

  /**
   * A site without rules says so instead of returning nothing.
   */
  public function testNoRules(): void {
    $this->actAs(['configure reviews']);

    $tool = $this->runTool('list_review_rules', []);

    $this->assertTrue($tool->getResult()->isSuccess());
    $this->assertStringContainsString('(no rules yet)', $this->message($tool));
    $this->assertSame([], $tool->getOutputValue('rules'));
  }

}
