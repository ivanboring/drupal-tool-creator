<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
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
 * Plugin implementation of the Apply Suggestion tool.
 */
#[Tool(
  id: 'ai_content_review_tools_apply_suggestion',
  label: new TranslatableMarkup('Apply Suggestion'),
  description: new TranslatableMarkup('Writes one AI review suggestion into the content: replaces the targeted field with the suggested text and saves. Use it only when the user wants the suggestion applied, used or put in now. To accept or ignore a suggestion without changing the content, use ai_content_review_tools_decide_suggestion. Get suggestion ids from ai_content_review_tools_get_content_review.'),
  operation: ToolOperation::Write,
  destructive: TRUE,
  input_definitions: [
    'suggestion_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Suggestion id'),
      description: new TranslatableMarkup('Suggestion id, e.g. 1.'),
      required: TRUE,
    ),
  ],
  output_definitions: [
    'content_id' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Content id'),
      description: new TranslatableMarkup('The content item that was changed.'),
    ),
    'field' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field'),
      description: new TranslatableMarkup('The field that was written.'),
    ),
    'title' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title'),
      description: new TranslatableMarkup('Title of the content item after the change.'),
    ),
  ],
  permission: 'run reviews',
)]
final class ApplySuggestionTool extends ToolBase {

  /**
   * The shared review tool helper.
   */
  protected ReviewToolsHelper $helper;

  /**
   * The database connection, for the transaction around apply + mark.
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->helper = $container->get('ai_content_review_tools.helper');
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $id = (int) $values['suggestion_id'];
      [$suggestion, $record, $entity] = $this->helper->loadSuggestion($id);
      if ($suggestion === NULL || $record === NULL || $entity === NULL) {
        throw new \InvalidArgumentException(sprintf(
          'No suggestion with id %d on content that still exists. Use ai_content_review_tools_get_content_review to list a content item\'s suggestions and their ids.',
          $id,
        ));
      }
      if ($suggestion->isApplied()) {
        throw new \InvalidArgumentException(sprintf(
          'Suggestion %d was already applied to "%s"; nothing changed.',
          $id,
          $entity->label(),
        ));
      }
      $field = $suggestion->getTarget();
      if ($field === NULL || $suggestion->getSuggestedValue() === NULL || $suggestion->getType() === 'general_recommendation') {
        throw new \InvalidArgumentException(sprintf(
          'Suggestion %d is a general recommendation that is not tied to a field, so it cannot be applied. Mark it with ai_content_review_tools_decide_suggestion instead.',
          $id,
        ));
      }
      if (!$record->isCurrent()) {
        throw new \InvalidArgumentException(sprintf(
          'Suggestion %d belongs to an older review of "%s": the criterion has been reviewed again since, so this suggestion is no longer current. Use ai_content_review_tools_get_content_review for the current suggestions.',
          $id,
          $entity->label(),
        ));
      }
      if (!$entity->hasField($field)) {
        throw new \InvalidArgumentException(sprintf('"%s" has no field "%s" to write the suggestion into.', $entity->label(), $field));
      }

      $title_before = (string) $entity->label();
      $transaction = $this->database->startTransaction();
      try {
        // The record type plugin writes the value and saves the entity, the
        // same path the module's own Apply button takes.
        $record->getType()->applySuggestion($entity, $suggestion);
        $suggestion->setApplied((int) $this->currentUser->id())->save();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      unset($transaction);

      return ExecutableResult::success(
        new TranslatableMarkup('Applied suggestion @id to "@title" (@type @eid): field "@field" is now "@value". The content was saved.', [
          '@id' => $id,
          '@title' => $title_before,
          '@type' => $entity->bundle(),
          '@eid' => $entity->id(),
          '@field' => $field,
          '@value' => $suggestion->getSuggestedValue(),
        ]),
        ['content_id' => (int) $entity->id(), 'field' => $field, 'title' => (string) $entity->label()],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * The declared "run reviews" permission is checked before this. On top of
   * it the account must be able to edit the reviewed content as it stands
   * now, as the module's own Apply endpoint requires
   * (InternalReviewRecord::canApplySuggestions()). An unknown suggestion is
   * left to doExecute(), which explains how to find a valid id.
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    [$suggestion, , $entity] = $this->helper->loadSuggestion((int) ($values['suggestion_id'] ?? 0));
    if ($suggestion === NULL || $entity === NULL) {
      $access = AccessResult::allowed()->setCacheMaxAge(0);
    }
    else {
      $access = $entity->access('update', $account, TRUE);
    }
    return $return_as_object ? $access : $access->isAllowed();
  }

}
