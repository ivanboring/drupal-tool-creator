<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_content_review\Entity\ReviewRecordInterface;
use Drupal\ai_content_review\Entity\ReviewRuleInterface;
use Drupal\ai_content_review\Entity\SuggestionInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\tool\Tool\ToolInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Boots the review module stack and seeds rules, records and suggestions.
 */
abstract class ReviewToolsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'file',
    'key',
    'ai',
    'modeler_api',
    'ai_agents',
    'entity',
    'tool',
    'ai_content_review',
    'ai_content_review_tools',
  ];

  /**
   * The tool plugin manager.
   *
   * @var \Drupal\tool\Tool\ToolManager
   */
  protected $toolManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('review_record');
    $this->installEntitySchema('suggestion');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'filter']);
    $this->toolManager = $this->container->get('plugin.manager.tool');

    // Take uid 1 so test accounts are never the superuser.
    User::create(['name' => 'superuser', 'status' => 1])->save();

    foreach (['article' => 'Article', 'page' => 'Basic page'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }

    $agents = $this->container->get('entity_type.manager')->getStorage('ai_agent');
    $agents->create([
      'id' => 'editorial_reviewer',
      'label' => 'Editorial reviewer',
      'description' => 'Scores one criterion.',
      'system_prompt' => 'Review.',
      'tools' => ['ai_content_review:content_review_result' => TRUE],
      'max_loops' => 3,
    ])->save();
    $agents->create([
      'id' => 'chatty_helper',
      'label' => 'Chatty helper',
      'description' => 'Cannot report review results.',
      'system_prompt' => 'Chat.',
      'tools' => [],
      'max_loops' => 3,
    ])->save();
  }

  /**
   * Makes a user with the given permissions the current user.
   *
   * @param string[] $permissions
   *   The permissions.
   */
  protected function actAs(array $permissions): UserInterface {
    $rid = 'r' . count(Role::loadMultiple());
    $role = Role::create(['id' => $rid, 'label' => $rid]);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $user = User::create(['name' => 'user_' . $rid, 'status' => 1, 'roles' => [$rid]]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    return $user;
  }

  /**
   * Runs a tool as the current user and returns it.
   *
   * @param string $name
   *   The tool name without the module prefix.
   * @param array $inputs
   *   The input values.
   */
  protected function runTool(string $name, array $inputs): ToolInterface {
    /** @var \Drupal\tool\Tool\ToolInterface $tool */
    $tool = $this->toolManager->createInstance('ai_content_review_tools_' . $name);
    foreach ($inputs as $key => $value) {
      $tool->setInputValue($key, $value);
    }
    $tool->execute();
    return $tool;
  }

  /**
   * The result message as an agent reads it.
   *
   * Drush and tool_ai_connector both pass the message through
   * PlainTextOutput::renderFromHtml(), which undoes placeholder escaping.
   */
  protected function message(ToolInterface $tool): string {
    return PlainTextOutput::renderFromHtml((string) $tool->getResult()->getMessage());
  }

  /**
   * A criterion definition as the module's rule form stores it.
   */
  protected function criterion(string $name, string $guidelines, int $pass, ?int $warn = NULL): array {
    return $this->container->get('ai_content_review_tools.helper')
      ->buildCriterion($name, 'editorial_reviewer', $guidelines, $pass, $warn);
  }

  /**
   * Creates the "Article quality" rule: Tone & voice (80/60), Inclusive (85).
   */
  protected function createArticleRule(): ReviewRuleInterface {
    $rule = $this->container->get('entity_type.manager')->getStorage('review_rule')->create([
      'id' => 'article_quality',
      'label' => 'Article quality',
      'entity_type' => 'node',
      'bundles' => ['article'],
      'criteria' => [
        $this->criterion('Tone & voice', 'Warm and plain.', 80, 60),
        $this->criterion('Inclusive language', 'Flag exclusionary terms.', 85),
      ],
    ]);
    $rule->save();
    return $rule;
  }

  /**
   * Creates an article.
   */
  protected function createArticle(string $title, bool $published = TRUE, string $type = 'article'): NodeInterface {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => (int) $published, 'uid' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Creates a completed review record for a node and criterion.
   */
  protected function createRecord(NodeInterface $node, array $criterion, int $score): ReviewRecordInterface {
    /** @var \Drupal\ai_content_review\Entity\ReviewRecordInterface $record */
    $record = $this->container->get('entity_type.manager')->getStorage('review_record')->create([
      'type' => 'internal',
      'criterion_id' => 'agent_based',
      'criterion_label' => $criterion['label'],
      'triggered_by' => 'manual',
      'triggered_by_uid' => 1,
      'modality' => 'interactive',
      'input_hash' => $this->container->get('ai_content_review.input_hasher')->hash($criterion['configuration']),
      'status' => 'complete',
      'score' => $score,
      'severity' => 'warning',
      'explanation' => "Scored $score.",
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'entity_revision_id' => $node->getRevisionId(),
      'reviewed_at' => \Drupal::time()->getRequestTime(),
    ]);
    $record->save();
    return $record;
  }

  /**
   * Attaches a suggestion to a record.
   */
  protected function createSuggestion(ReviewRecordInterface $record, string $type, ?string $target, ?string $original, string $suggested): SuggestionInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('suggestion');
    /** @var \Drupal\ai_content_review\Entity\SuggestionInterface $suggestion */
    $suggestion = $storage->create([
      'review_record' => $record->id(),
      'type' => $type,
      'target' => $target,
      'original_value' => $original,
      'suggested_value' => $suggested,
      'rationale' => 'Because.',
    ]);
    $suggestion->save();
    $ids = array_map(static fn($s) => $s->id(), $record->getSuggestions());
    $ids[] = $suggestion->id();
    $record->set('suggestions', $ids)->save();
    return $suggestion;
  }

  /**
   * Reloads a review rule from storage.
   */
  protected function reloadRule(string $id): ReviewRuleInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('review_rule');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

}
