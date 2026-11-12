<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_review\InputHasherInterface;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Update Review Criterion tool.
 */
#[Tool(
  id: 'ai_content_review_tools_update_review_criterion',
  label: new TranslatableMarkup('Update Review Criterion'),
  description: new TranslatableMarkup('Changes one criterion of an AI content review rule: name, scores, agent or guidelines. Only the values you pass change; a new agent or guidelines makes earlier results for the criterion stop counting.'),
  operation: ToolOperation::Write,
  destructive: TRUE,
  input_definitions: [
    'rule' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule'),
      description: new TranslatableMarkup('Rule name or id, e.g. "Article quality".'),
      required: TRUE,
    ),
    'criterion' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Criterion'),
      description: new TranslatableMarkup('The criterion to change, by name or position in the rule, e.g. "Tone & voice" or "1".'),
      required: TRUE,
    ),
    'new_name' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('New name'),
      description: new TranslatableMarkup('Optional. New name.'),
      required: FALSE,
    ),
    'pass_score' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Passing score'),
      description: new TranslatableMarkup('Optional. New passing score (0-100), e.g. 75.'),
      required: FALSE,
      constraints: ['Range' => ['min' => 0, 'max' => 100]],
    ),
    'warn_score' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Warning score'),
      description: new TranslatableMarkup('Optional. New warning score (0-100), lower than the passing score.'),
      required: FALSE,
      constraints: ['Range' => ['min' => 0, 'max' => 100]],
    ),
    'agent' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('AI agent'),
      description: new TranslatableMarkup('Optional. Another AI agent, by name or id.'),
      required: FALSE,
    ),
    'guidelines' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Guidelines'),
      description: new TranslatableMarkup('Optional. New guidelines (the rubric).'),
      required: FALSE,
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
      description: new TranslatableMarkup('Position (1-based) of the changed criterion.'),
    ),
    'changes' => new ListOutputDefinition(
      label: new TranslatableMarkup('Changes'),
      description: new TranslatableMarkup('What changed, one line per value.'),
      item_definition: new OutputDefinition(
        data_type: 'string',
        label: new TranslatableMarkup('Change'),
        description: new TranslatableMarkup('One change.'),
      ),
    ),
    'results_reset' => new OutputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Results reset'),
      description: new TranslatableMarkup('TRUE when earlier review results for this criterion no longer count.'),
    ),
  ],
  permission: 'configure reviews',
)]
final class UpdateReviewCriterionTool extends ToolBase {

  /**
   * The shared review tool helper.
   */
  protected ReviewToolsHelper $helper;

  /**
   * The module's input hasher.
   */
  protected InputHasherInterface $inputHasher;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->helper = $container->get('ai_content_review_tools.helper');
    $instance->inputHasher = $container->get('ai_content_review.input_hasher');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $rule_ref = trim((string) $values['rule']);
      $criterion_ref = trim((string) $values['criterion']);
      $rule = NULL;
      try {
        $rule = $this->helper->findRule($rule_ref);
      }
      catch (\InvalidArgumentException $e) {
        // An agent that was not given a rule may repeat the criterion name
        // in the rule input: that counts as no rule. Any other unknown rule
        // still fails.
        if (mb_strtolower($rule_ref) !== mb_strtolower($criterion_ref)) {
          throw $e;
        }
      }
      if ($rule === NULL) {
        [$rule, $index] = $this->helper->findCriterionInAnyRule($criterion_ref);
      }
      else {
        $index = $this->helper->findCriterion($rule, $criterion_ref);
      }
      $criteria = array_values($rule->getCriteria());
      $c = $criteria[$index];
      $old_name = (string) ($c['label'] ?? '');
      $old_hash = $this->inputHasher->hash($c['configuration'] ?? []);
      $changes = [];

      $new_name = trim((string) ($values['new_name'] ?? ''));
      if ($new_name !== '' && $new_name !== $old_name) {
        foreach ($criteria as $i => $other) {
          if ($i !== $index && strcasecmp((string) ($other['label'] ?? ''), $new_name) === 0) {
            throw new \InvalidArgumentException(sprintf('Rule "%s" already has a criterion named "%s".', $rule->label(), $new_name));
          }
        }
        $changes[] = sprintf('name "%s" -> "%s"', $old_name, $new_name);
        $c['label'] = $new_name;
      }

      $old_pass = $c['pass_threshold'] ?? NULL;
      $old_warn = $c['warn_threshold'] ?? NULL;
      $pass = isset($values['pass_score']) ? (int) $values['pass_score'] : ($old_pass === NULL ? NULL : (int) $old_pass);
      $warn = isset($values['warn_score']) ? (int) $values['warn_score'] : ($old_warn === NULL ? NULL : (int) $old_warn);
      if ($pass !== $old_pass || $warn !== $old_warn) {
        try {
          $this->helper->assertScores($pass, $warn);
        }
        catch (\InvalidArgumentException $e) {
          if (!isset($values['warn_score']) && $warn !== NULL) {
            throw new \InvalidArgumentException($e->getMessage() . sprintf(' The current warning score is %d; pass a lower warn_score as well.', $warn));
          }
          throw $e;
        }
        if ($pass !== $old_pass) {
          $changes[] = sprintf('passing score %s -> %d', $old_pass ?? 'not set', $pass);
        }
        if ($warn !== $old_warn) {
          $changes[] = sprintf('warning score %s -> %s', $old_warn ?? 'not set', $warn ?? 'not set');
        }
        $c['pass_threshold'] = $pass;
        $c['warn_threshold'] = $warn;
      }

      $configuration = $c['configuration'] ?? [];
      $configuration_changed = FALSE;
      if (isset($values['agent']) && trim((string) $values['agent']) !== '') {
        $agent_id = $this->helper->findAgent((string) $values['agent']);
        if ($agent_id !== ($configuration['agent_id'] ?? '')) {
          $changes[] = sprintf('agent %s -> %s', $configuration['agent_id'] ?? 'none', $agent_id);
          $configuration['agent_id'] = $agent_id;
          $configuration_changed = TRUE;
        }
      }
      if (isset($values['guidelines']) && trim((string) $values['guidelines']) !== '') {
        $guidelines = trim((string) $values['guidelines']);
        if ($guidelines !== ($configuration['prompt_template'] ?? '')) {
          $changes[] = 'guidelines replaced';
          $configuration['prompt_template'] = $guidelines;
          $configuration_changed = TRUE;
        }
      }
      if ($configuration_changed) {
        $c['configuration'] = $this->helper->criterionConfiguration($configuration);
      }

      if (!$changes) {
        throw new \InvalidArgumentException(sprintf(
          'Nothing to change for criterion "%s": pass at least one of new_name, pass_score, warn_score, agent or guidelines with a different value. It is now: %s.',
          $old_name,
          $this->helper->describeCriterion($criteria[$index], $index + 1),
        ));
      }

      $criteria[$index] = $c;
      $rule->set('criteria', $criteria);
      if ($violations = $this->helper->violations($rule)) {
        throw new \InvalidArgumentException('The rule would not be valid: ' . implode('; ', $violations));
      }
      $rule->save();

      $reset = $this->inputHasher->hash($c['configuration']) !== $old_hash;
      return ExecutableResult::success(
        new TranslatableMarkup('Updated criterion "@name" in rule "@rule": @changes. @effect It is now: @now.', [
          '@name' => $old_name,
          '@rule' => $rule->label(),
          '@changes' => implode('; ', $changes),
          '@effect' => $reset
            ? 'Because what the AI is asked changed, earlier review results for this criterion no longer count; queue new reviews with ai_content_review_tools_queue_reviews.'
            : 'Earlier review results still count and are graded with the new scores.',
          '@now' => $this->helper->describeCriterion($c, $index + 1),
        ]),
        [
          'rule_id' => (string) $rule->id(),
          'criterion_position' => $index + 1,
          'changes' => $changes,
          'results_reset' => $reset,
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
