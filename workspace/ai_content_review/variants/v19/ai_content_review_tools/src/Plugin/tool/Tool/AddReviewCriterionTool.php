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
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Add Review Criterion tool.
 */
#[Tool(
  id: 'ai_content_review_tools_add_review_criterion',
  label: new TranslatableMarkup('Add Review Criterion'),
  description: new TranslatableMarkup('Adds one criterion (a quality check the AI scores from 0 to 100) to an existing AI content review rule. The other criteria of the rule are not changed. To change an existing criterion, use ai_content_review_tools_update_review_criterion instead.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  input_definitions: [
    'rule' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule'),
      description: new TranslatableMarkup('The rule to add to, by name or id, e.g. "Article quality".'),
      required: TRUE,
    ),
    'criterion_name' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Criterion name'),
      description: new TranslatableMarkup('Name of the new criterion, e.g. "Inclusive language".'),
      required: TRUE,
    ),
    'agent' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('AI agent'),
      description: new TranslatableMarkup('The AI agent that scores the criterion, by name or id, e.g. "Editorial reviewer". ai_content_review_tools_list_review_rules lists the agents you can use.'),
      required: TRUE,
    ),
    'guidelines' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Guidelines'),
      description: new TranslatableMarkup('What the AI should judge for this criterion, in plain words; it is sent to the AI as the rubric.'),
      required: FALSE,
      default_value: '',
    ),
    'pass_score' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Passing score'),
      description: new TranslatableMarkup('Score (0-100) from which the criterion passes, e.g. 85.'),
      required: TRUE,
      constraints: ['Range' => ['min' => 0, 'max' => 100]],
    ),
    'warn_score' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Warning score'),
      description: new TranslatableMarkup('Optional. Scores from this value up to the passing score count as a warning instead of a fail. Must be lower than pass_score.'),
      required: FALSE,
      constraints: ['Range' => ['min' => 0, 'max' => 100]],
    ),
  ],
  output_definitions: [
    'rule_id' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule id'),
      description: new TranslatableMarkup('Machine name of the rule.'),
    ),
    'criterion_position' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Criterion position'),
      description: new TranslatableMarkup('Position (1-based) of the new criterion in the rule.'),
    ),
    'rule' => new MapOutputDefinition(
      label: new TranslatableMarkup('Rule'),
      description: new TranslatableMarkup('The rule with all its criteria.'),
    ),
  ],
  permission: 'configure reviews',
)]
final class AddReviewCriterionTool extends ToolBase {

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
      $name = trim((string) $values['criterion_name']);
      if ($name === '') {
        throw new \InvalidArgumentException('The criterion name must not be empty.');
      }
      $criteria = array_values($rule->getCriteria());
      foreach ($criteria as $i => $c) {
        if (strcasecmp((string) ($c['label'] ?? ''), $name) === 0) {
          throw new \InvalidArgumentException(sprintf(
            'Rule "%s" already has a criterion named "%s" (position %d). To change it, use ai_content_review_tools_update_review_criterion.',
            $rule->label(),
            $c['label'],
            $i + 1,
          ));
        }
      }

      $agent_id = $this->helper->findAgent((string) $values['agent']);
      $pass = (int) $values['pass_score'];
      $warn = isset($values['warn_score']) ? (int) $values['warn_score'] : NULL;
      $this->helper->assertScores($pass, $warn);
      $criterion = $this->helper->buildCriterion($name, $agent_id, (string) ($values['guidelines'] ?? ''), $pass, $warn);

      $criteria[] = $criterion;
      $rule->set('criteria', $criteria);
      if ($violations = $this->helper->violations($rule)) {
        throw new \InvalidArgumentException('The rule would not be valid: ' . implode('; ', $violations));
      }
      $rule->save();
      $position = count($criteria);

      return ExecutableResult::success(
        new TranslatableMarkup('Added criterion @criterion to rule "@rule". Its other criteria were not changed. Criteria now: @all.', [
          '@criterion' => $this->helper->describeCriterion($criterion, $position),
          '@rule' => $rule->label(),
          '@all' => $this->helper->criteriaList($rule),
        ]),
        [
          'rule_id' => (string) $rule->id(),
          'criterion_position' => $position,
          'rule' => $this->helper->describeRule($rule),
        ],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

}
