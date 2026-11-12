<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the List Review Rules tool.
 */
#[Tool(
  id: 'ai_content_review_tools_list_review_rules',
  label: new TranslatableMarkup('List Review Rules'),
  description: new TranslatableMarkup('Lists every AI content review rule with its criteria (position, name, passing and warning scores, AI agent, guidelines) and whether it is enabled, plus the AI agents a criterion can use. Call it first to find the exact rule, criterion or agent before changing a rule.'),
  operation: ToolOperation::Read,
  destructive: FALSE,
  input_definitions: [],
  output_definitions: [
    'rules' => new ListOutputDefinition(
      label: new TranslatableMarkup('Rules'),
      description: new TranslatableMarkup('Each rule: id, label, enabled, entity_type, content_types and its criteria (position, name, pass_score, warn_score, agent, agent_label, guidelines).'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Rule'),
        description: new TranslatableMarkup('One review rule.'),
      ),
    ),
    'agents' => new ListOutputDefinition(
      label: new TranslatableMarkup('Agents'),
      description: new TranslatableMarkup('The AI agents a criterion can use: id and label.'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Agent'),
        description: new TranslatableMarkup('One AI agent.'),
      ),
    ),
  ],
  permission: 'configure reviews+view review records',
)]
final class ListReviewRulesTool extends ToolBase {

  /**
   * The shared review tool helper.
   */
  protected ReviewToolsHelper $helper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->helper = $container->get('ai_content_review_tools.helper');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $rules = [];
      $lines = [];
      foreach ($this->helper->rules() as $rule) {
        $rules[] = $this->helper->describeRule($rule);
        $criteria = [];
        foreach (array_values($rule->getCriteria()) as $i => $c) {
          $criteria[] = $this->helper->describeCriterion($c, $i + 1);
        }
        $bundles = $rule->getBundles();
        $lines[] = sprintf(
          '- "%s" (id %s, %s) reviews %s of type %s. Criteria: %s.',
          $rule->label(),
          $rule->id(),
          $rule->status() ? 'enabled' : 'disabled',
          $rule->getReviewedEntityTypeId() ?: 'content',
          $bundles ? implode(', ', $bundles) : 'any',
          $criteria ? implode('; ', $criteria) : 'none',
        );
      }

      $agents = [];
      $agent_names = [];
      foreach ($this->helper->eligibleAgents() as $id => $label) {
        $agents[] = ['id' => $id, 'label' => $label];
        $agent_names[] = sprintf('"%s" (%s)', $label, $id);
      }

      return ExecutableResult::success(
        new TranslatableMarkup("@count review rule(s).\n@rules\nAI agents a criterion can use: @agents.", [
          '@count' => count($rules),
          '@rules' => $lines ? implode("\n", $lines) : '(no rules yet)',
          '@agents' => $agent_names ? implode(', ', $agent_names) : 'none',
        ]),
        ['rules' => $rules, 'agents' => $agents],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

}
