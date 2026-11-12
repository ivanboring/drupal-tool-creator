<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_review\Batch\BatchEnqueuer;
use Drupal\ai_content_review\Entity\InternalReviewRuleInterface;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Queue Reviews tool.
 */
#[Tool(
  id: 'ai_content_review_tools_queue_reviews',
  label: new TranslatableMarkup('Queue Reviews'),
  description: new TranslatableMarkup('Queues AI reviews of every published item an AI content review rule covers, one per item and criterion. They run later on cron and spend AI provider budget; items already reviewed with the same settings, or already queued, are skipped.'),
  operation: ToolOperation::Trigger,
  destructive: TRUE,
  input_definitions: [
    'rule' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Rule'),
      description: new TranslatableMarkup('Rule name or id, e.g. "Article quality".'),
      required: TRUE,
    ),
  ],
  output_definitions: [
    'queued' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Queued'),
      description: new TranslatableMarkup('Reviews added to the queue (items x criteria).'),
    ),
    'skipped' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Skipped'),
      description: new TranslatableMarkup('Reviews skipped because they were already done with the same settings or already queued.'),
    ),
    'items' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Items'),
      description: new TranslatableMarkup('Published content items examined.'),
    ),
  ],
  permission: 'run batch reviews',
)]
final class QueueReviewsTool extends ToolBase {

  /**
   * How many content items one call queues at most.
   */
  private const MAX_ITEMS = 500;

  /**
   * The shared review tool helper.
   */
  protected ReviewToolsHelper $helper;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The module's batch enqueuer.
   */
  protected BatchEnqueuer $enqueuer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->helper = $container->get('ai_content_review_tools.helper');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->enqueuer = $container->get('ai_content_review.batch_enqueuer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $rule = $this->helper->findRule((string) $values['rule']);
      if (!$rule->status()) {
        throw new \InvalidArgumentException(sprintf(
          'Rule "%s" is disabled, so it cannot review anything. Enable it first with ai_content_review_tools_set_review_rule_status.',
          $rule->label(),
        ));
      }
      if (!$rule instanceof InternalReviewRuleInterface || $rule->getReviewedEntityTypeId() === '') {
        throw new \InvalidArgumentException(sprintf('Rule "%s" does not review site content, so there is nothing to queue.', $rule->label()));
      }

      $entity_type_id = $rule->getReviewedEntityTypeId();
      $definition = $this->entityTypeManager->getDefinition($entity_type_id);
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $query = $storage->getQuery()->accessCheck(TRUE)
        ->sort($definition->getKey('id'))
        ->range(0, self::MAX_ITEMS + 1);
      if ($rule->getBundles() && ($bundle_key = $definition->getKey('bundle'))) {
        $query->condition($bundle_key, $rule->getBundles(), 'IN');
      }
      if ($published_key = $definition->getKey('published')) {
        $query->condition($published_key, 1);
      }
      $ids = $query->execute();
      $truncated = count($ids) > self::MAX_ITEMS;
      $ids = array_slice($ids, 0, self::MAX_ITEMS);

      $queued = 0;
      $skipped = 0;
      $items = 0;
      foreach (array_chunk($ids, 50) as $chunk) {
        $entities = array_filter(
          $storage->loadMultiple($chunk),
          fn($entity) => $entity->access('view', $this->currentUser),
        );
        $result = $this->enqueuer->enqueueSubjects(array_values($entities), ['rules' => [(string) $rule->id()]]);
        $queued += $result['queued'];
        $skipped += $result['filtered'];
        $items += $result['subjects'];
      }

      return ExecutableResult::success(
        new TranslatableMarkup('Queued @queued review(s) for rule "@rule": @items published item(s) x @criteria criteria, @skipped skipped (already reviewed with the same settings, or already queued). They run in the background on cron and spend AI provider budget; results appear once they have run.@more', [
          '@queued' => $queued,
          '@rule' => $rule->label(),
          '@items' => $items,
          '@criteria' => count($rule->getCriteria()),
          '@skipped' => $skipped,
          '@more' => $truncated ? sprintf(' Only the first %d items were queued; run this tool again for the rest.', self::MAX_ITEMS) : '',
        ]),
        ['queued' => $queued, 'skipped' => $skipped, 'items' => $items],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

}
