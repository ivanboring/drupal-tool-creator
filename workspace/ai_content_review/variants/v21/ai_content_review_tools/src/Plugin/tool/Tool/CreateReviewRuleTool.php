<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Create Review Rule tool.
 */
#[Tool(
  id: 'ai_content_review_tools_create_review_rule',
  label: new TranslatableMarkup('Create Review Rule'),
  description: new TranslatableMarkup('Creates an AI content review rule for one or more content types, with its first criterion (a check the AI scores 0-100). Add more criteria with ai_content_review_tools_add_review_criterion.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  input_definitions: [
    'label' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule name'),
      description: new TranslatableMarkup('Name of the new rule, e.g. "Article quality".'),
      required: TRUE,
    ),
    'content_types' => new ListInputDefinition(
      label: new TranslatableMarkup('Content types'),
      description: new TranslatableMarkup('Content types the rule reviews, by name or machine name, e.g. ["article"]. Leave empty to review every content type.'),
      required: FALSE,
      default_value: [],
      item_definition: new InputDefinition(
        data_type: 'string',
        label: new TranslatableMarkup('Content type'),
        description: new TranslatableMarkup('A content type name or machine name, e.g. "article".'),
      ),
    ),
    'criterion_name' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Criterion name'),
      description: new TranslatableMarkup('Name of the first criterion, e.g. "Tone & voice".'),
      required: TRUE,
    ),
    'agent' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('AI agent'),
      description: new TranslatableMarkup('AI agent that scores it, by name or id, e.g. "Editorial reviewer".'),
      required: TRUE,
    ),
    'guidelines' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Guidelines'),
      description: new TranslatableMarkup('What the AI should judge, in plain words (the rubric).'),
      required: FALSE,
      default_value: '',
    ),
    'pass_score' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Passing score'),
      description: new TranslatableMarkup('Score (0-100) from which the criterion passes, e.g. 80.'),
      required: TRUE,
      constraints: ['Range' => ['min' => 0, 'max' => 100]],
    ),
    'warn_score' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Warning score'),
      description: new TranslatableMarkup('Optional. Scores from this value up to pass_score warn instead of failing, e.g. 60.'),
      required: FALSE,
      constraints: ['Range' => ['min' => 0, 'max' => 100]],
    ),
    'entity_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity type'),
      description: new TranslatableMarkup('Keep "node" unless told otherwise.'),
      required: FALSE,
      default_value: 'node',
    ),
  ],
  output_definitions: [
    'rule_id' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule id'),
      description: new TranslatableMarkup('Machine name of the created rule.'),
    ),
    'rule' => new MapOutputDefinition(
      label: new TranslatableMarkup('Rule'),
      description: new TranslatableMarkup('The created rule with its criteria.'),
    ),
  ],
  permission: 'configure reviews',
)]
final class CreateReviewRuleTool extends ToolBase {

  /**
   * The shared review tool helper.
   */
  protected ReviewToolsHelper $helper;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->helper = $container->get('ai_content_review_tools.helper');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $label = trim((string) $values['label']);
      if ($label === '') {
        throw new \InvalidArgumentException('The rule name must not be empty.');
      }
      foreach ($this->helper->rules() as $existing) {
        if (strcasecmp((string) $existing->label(), $label) === 0) {
          throw new \InvalidArgumentException(sprintf(
            'A review rule named "%s" already exists (id %s). To add a criterion to it, use ai_content_review_tools_add_review_criterion.',
            $existing->label(),
            $existing->id(),
          ));
        }
      }

      $entity_type = $this->helper->resolveEntityType(trim((string) ($values['entity_type'] ?? '')) ?: 'node');
      $bundles = $this->helper->resolveBundles($entity_type, (array) ($values['content_types'] ?? []));
      $agent_id = $this->helper->findAgent((string) $values['agent']);
      $pass = (int) $values['pass_score'];
      $warn = isset($values['warn_score']) ? (int) $values['warn_score'] : NULL;
      $this->helper->assertScores($pass, $warn);
      $criterion = $this->helper->buildCriterion((string) $values['criterion_name'], $agent_id, (string) ($values['guidelines'] ?? ''), $pass, $warn);
      if ($criterion['label'] === '') {
        throw new \InvalidArgumentException('The criterion name must not be empty.');
      }

      /** @var \Drupal\ai_content_review\Entity\ReviewRuleInterface $rule */
      $rule = $this->entityTypeManager->getStorage('review_rule')->create([
        'id' => $this->machineName($label),
        'label' => $label,
        'entity_type' => $entity_type,
        'bundles' => $bundles,
        'criteria' => [$criterion],
        'status' => TRUE,
      ]);
      if ($violations = $this->helper->violations($rule)) {
        throw new \InvalidArgumentException('The rule is not valid: ' . implode('; ', $violations));
      }
      $rule->save();

      return ExecutableResult::success(
        new TranslatableMarkup('Created review rule "@label" (id @id), enabled, reviewing @type of type @bundles, with criterion @criterion. Guidelines: "@guidelines".', [
          '@label' => $rule->label(),
          '@id' => $rule->id(),
          '@type' => $entity_type,
          '@bundles' => $bundles ? implode(', ', $bundles) : 'any',
          '@criterion' => $this->helper->describeCriterion($criterion, 1),
          '@guidelines' => $criterion['configuration']['prompt_template'] ?? '',
        ]),
        ['rule_id' => (string) $rule->id(), 'rule' => $this->helper->describeRule($rule)],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

  /**
   * A machine name for the rule, unique among existing rules.
   */
  private function machineName(string $label): string {
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_') ?: 'review_rule';
    $base = substr($base, 0, 60);
    $storage = $this->entityTypeManager->getStorage('review_rule');
    $id = $base;
    for ($i = 2; $storage->load($id) !== NULL; $i++) {
      $id = $base . '_' . $i;
    }
    return $id;
  }

}
