<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel\Tool;

use Drupal\Tests\ai_content_review_tools\Kernel\ReviewToolsKernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\ai_content_review_tools\Plugin\tool\Tool\CreateReviewRuleTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests execution of the Create Review Rule tool.
 */
#[CoversClass(CreateReviewRuleTool::class)]
#[Group('ai_content_review_tools')]
#[RunTestsInSeparateProcesses]
final class CreateReviewRuleToolTest extends ReviewToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->actAs(['configure reviews']);
  }

  /**
   * Creates a valid rule, resolving the content type and agent by label.
   */
  public function testSuccessfulExecution(): void {
    $tool = $this->runTool('create_review_rule', [
      'label' => 'Article quality',
      'content_types' => ['Article'],
      'criterion_name' => 'Tone & voice',
      'agent' => 'Editorial reviewer',
      'guidelines' => 'Second person, active voice.',
      'pass_score' => 80,
      'warn_score' => 60,
    ]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame('article_quality', $tool->getOutputValue('rule_id'));
    $this->assertStringContainsString('Created review rule "Article quality" (id article_quality)', $this->message($tool));

    $rule = $this->reloadRule('article_quality');
    $this->assertTrue($rule->status());
    $this->assertSame('node', $rule->getReviewedEntityTypeId());
    $this->assertSame(['article'], $rule->getBundles());
    $criteria = $rule->getCriteria();
    $this->assertCount(1, $criteria);
    $this->assertSame('Tone & voice', $criteria[0]['label']);
    $this->assertSame(80, $criteria[0]['pass_threshold']);
    $this->assertSame(60, $criteria[0]['warn_threshold']);
    $this->assertSame('editorial_reviewer', $criteria[0]['configuration']['agent_id']);
    $this->assertSame('Second person, active voice.', $criteria[0]['configuration']['prompt_template']);
    $this->assertCount(0, $rule->getTypedData()->validate());
    // The same values the module's own form stores (config storage reorders
    // keys to the schema), so the input hash lines up with UI-made criteria.
    $expected = $this->criterion('Tone & voice', 'Second person, active voice.', 80, 60);
    $this->assertEquals($expected, $criteria[0]);
    $hasher = $this->container->get('ai_content_review.input_hasher');
    $this->assertSame($hasher->hash($expected['configuration']), $hasher->hash($criteria[0]['configuration']));
  }

  /**
   * A second rule with the same name is refused with a pointer to add.
   */
  public function testDuplicateNameFails(): void {
    $this->createArticleRule();

    $tool = $this->runTool('create_review_rule', [
      'label' => 'article quality',
      'criterion_name' => 'SEO',
      'agent' => 'editorial_reviewer',
      'pass_score' => 70,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('ai_content_review_tools_add_review_criterion', $this->message($tool));
  }

  /**
   * An agent without the review result tool is refused; valid agents listed.
   */
  public function testIneligibleAgentFails(): void {
    $tool = $this->runTool('create_review_rule', [
      'label' => 'Article quality',
      'criterion_name' => 'Tone',
      'agent' => 'Chatty helper',
      'pass_score' => 80,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('"Editorial reviewer" (editorial_reviewer)', $this->message($tool));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('review_rule')->load('article_quality'));
  }

  /**
   * A warning score at or above the passing score is refused.
   */
  public function testWarnNotBelowPassFails(): void {
    $tool = $this->runTool('create_review_rule', [
      'label' => 'Article quality',
      'criterion_name' => 'Tone',
      'agent' => 'editorial_reviewer',
      'pass_score' => 70,
      'warn_score' => 70,
    ]);

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('must be lower than the passing score', $this->message($tool));
  }

  /**
   * An unknown content type is refused with the available ones.
   */
  public function testUnknownContentTypeFails(): void {
    foreach (['blog', 'blogs'] as $type) {
      $tool = $this->runTool('create_review_rule', [
        'label' => 'Blog quality',
        'content_types' => [$type],
        'criterion_name' => 'Tone',
        'agent' => 'editorial_reviewer',
        'pass_score' => 70,
      ]);

      $this->assertFalse($tool->getResult()->isSuccess(), $type);
      $this->assertStringContainsString(sprintf('No node type matches "%s". Available: ', $type), $this->message($tool));
      $this->assertStringContainsString('"Article" (article)', $this->message($tool));
    }
  }

  /**
   * Content types given in the plural resolve to their one type.
   */
  public function testPluralContentTypes(): void {
    $tool = $this->runTool('create_review_rule', [
      'label' => 'Site quality',
      'content_types' => ['articles', 'pages'],
      'criterion_name' => 'Tone',
      'agent' => 'editorial_reviewer',
      'pass_score' => 70,
    ]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame(['article', 'page'], $this->reloadRule('site_quality')->getBundles());
  }

  /**
   * Content types written the way people write them resolve to their type.
   */
  public function testContentTypesAsPeopleWriteThem(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('review_rule');
    $forms = [
      'Article content type',
      'Article type',
      'Content: Article',
      'node:article',
      'Article (article)',
      'Article (Content)',
      'Basic pages',
    ];
    foreach ($forms as $i => $form) {
      $tool = $this->runTool('create_review_rule', [
        'label' => 'Rule ' . $i,
        'content_types' => [$form],
        'criterion_name' => 'Tone',
        'agent' => 'editorial_reviewer',
        'pass_score' => 70,
      ]);
      $this->assertTrue($tool->getResult()->isSuccess(), $form . ': ' . $this->message($tool));
      $this->assertSame([$form === 'Basic pages' ? 'page' : 'article'], $storage->load('rule_' . $i)->getBundles(), $form);
    }
  }

  /**
   * The admin UI's names: "Content" for the entity type, "AI agent: ...".
   */
  public function testAdminUiNames(): void {
    $tool = $this->runTool('create_review_rule', [
      'label' => 'Article quality',
      'entity_type' => 'Content',
      'content_types' => ['Content', 'Article'],
      'criterion_name' => 'Tone',
      'agent' => 'AI agent: Editorial reviewer',
      'pass_score' => 80,
      'warn_score' => 60,
    ]);

    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $rule = $this->reloadRule('article_quality');
    $this->assertSame('node', $rule->getReviewedEntityTypeId());
    $this->assertSame(['article'], $rule->getBundles());
    $this->assertSame('editorial_reviewer', $rule->getCriteria()[0]['configuration']['agent_id']);

    $tool = $this->runTool('create_review_rule', [
      'label' => 'Page quality',
      'content_types' => ['page'],
      'criterion_name' => 'Tone',
      'agent' => 'Editorial reviewer agent',
      'pass_score' => 80,
    ]);
    $this->assertTrue($tool->getResult()->isSuccess(), $this->message($tool));
    $this->assertSame('editorial_reviewer', $this->reloadRule('page_quality')->getCriteria()[0]['configuration']['agent_id']);
  }

  /**
   * The entity type alone, generic words or a shared name still fail.
   */
  public function testVagueNamesFail(): void {
    NodeType::create(['type' => 'article_digest', 'name' => 'Article digest'])->save();
    $tool = $this->runTool('create_review_rule', [
      'label' => 'Vague',
      'content_types' => ['Content'],
      'criterion_name' => 'Tone',
      'agent' => 'editorial_reviewer',
      'pass_score' => 70,
    ]);
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('"Content" is the entity type (node), not one of its content types. Name the content type(s) instead: "Article" (article), ', $this->message($tool));

    foreach (['Content types', 'Article stuff', 'blogs'] as $form) {
      $tool = $this->runTool('create_review_rule', [
        'label' => 'Vague',
        'content_types' => [$form],
        'criterion_name' => 'Tone',
        'agent' => 'editorial_reviewer',
        'pass_score' => 70,
      ]);
      $this->assertFalse($tool->getResult()->isSuccess(), $form);
    }
    foreach (['Marketing agent', 'AI agent', 'Content'] as $value) {
      $tool = $this->runTool('create_review_rule', [
        'label' => 'Vague',
        'content_types' => ['article'],
        'criterion_name' => 'Tone',
        'agent' => $value,
        'pass_score' => 70,
      ]);
      $this->assertFalse($tool->getResult()->isSuccess(), $value);
    }
    $tool = $this->runTool('create_review_rule', [
      'label' => 'Vague',
      'entity_type' => 'Things',
      'criterion_name' => 'Tone',
      'agent' => 'editorial_reviewer',
      'pass_score' => 70,
    ]);
    $this->assertStringContainsString('"Things" is not a content entity type.', $this->message($tool));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('review_rule')->load('vague'));
  }

  /**
   * Tests that a missing required input produces a failure result.
   */
  public function testMissingRequiredInputFails(): void {
    $tool = $this->runTool('create_review_rule', ['label' => 'Article quality']);
    $this->assertFalse($tool->getResult()->isSuccess());
  }

}
