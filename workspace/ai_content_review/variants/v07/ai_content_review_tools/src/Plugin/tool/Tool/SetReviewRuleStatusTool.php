<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Set Review Rule Status tool.
 */
#[Tool(
  id: 'ai_content_review_tools_set_review_rule_status',
  label: new TranslatableMarkup('Set Review Rule Status'),
  description: new TranslatableMarkup('Enables or disables an AI content review rule without deleting it. A disabled rule keeps its criteria and settings but stops reviewing content until it is enabled again.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  input_definitions: [
    'rule' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule'),
      description: new TranslatableMarkup('The rule, by name or id, e.g. "Legal compliance".'),
      required: TRUE,
    ),
    'enabled' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Enabled'),
      description: new TranslatableMarkup('true to enable (turn on) the rule, false to disable (turn off) it.'),
      required: TRUE,
    ),
  ],
  output_definitions: [
    'rule_id' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule id'),
      description: new TranslatableMarkup('Machine name of the rule.'),
    ),
    'enabled' => new OutputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Enabled'),
      description: new TranslatableMarkup('Whether the rule is now enabled.'),
    ),
  ],
  permission: 'configure reviews',
)]
final class SetReviewRuleStatusTool extends ToolBase {

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
      $rule = $this->helper->findRule((string) $values['rule']);
      $enabled = (bool) $values['enabled'];
      $state = $enabled ? 'enabled' : 'disabled';
      if ((bool) $rule->status() === $enabled) {
        return ExecutableResult::success(
          new TranslatableMarkup('Rule "@rule" (id @id) was already @state; nothing changed.', [
            '@rule' => $rule->label(),
            '@id' => $rule->id(),
            '@state' => $state,
          ]),
          ['rule_id' => (string) $rule->id(), 'enabled' => $enabled],
        );
      }
      $enabled ? $rule->enable() : $rule->disable();
      $rule->save();
      return ExecutableResult::success(
        new TranslatableMarkup('Rule "@rule" (id @id) is now @state. @effect', [
          '@rule' => $rule->label(),
          '@id' => $rule->id(),
          '@state' => $state,
          '@effect' => $enabled
            ? 'It reviews its content again.'
            : 'Its criteria and settings are kept; it stops reviewing content until it is enabled again.',
        ]),
        ['rule_id' => (string) $rule->id(), 'enabled' => $enabled],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

}
