<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
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
 * Plugin implementation of the Decide Suggestion tool.
 */
#[Tool(
  id: 'ai_content_review_tools_decide_suggestion',
  label: new TranslatableMarkup('Decide Suggestion'),
  description: new TranslatableMarkup('Accepts or ignores one AI review suggestion by recording the decision: "accepted", "ignored", or "undecided" to reset it. It does not change the content. Use it when the user says to accept, approve, ignore, dismiss or reject a suggestion. Only when the user wants the suggested text written into the content now, use ai_content_review_tools_apply_suggestion instead. Get suggestion ids from ai_content_review_tools_get_content_review.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  input_definitions: [
    'suggestion_id' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Suggestion id'),
      description: new TranslatableMarkup('The suggestion id, as listed by ai_content_review_tools_get_content_review, e.g. 2.'),
      required: TRUE,
    ),
    'decision' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Decision'),
      description: new TranslatableMarkup('"accepted" (the editor agrees and will act on it), "ignored" (dismissed) or "undecided" (reset).'),
      required: TRUE,
      constraints: ['Choice' => ['choices' => ['accepted', 'ignored', 'undecided']]],
    ),
  ],
  output_definitions: [
    'suggestion_id' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Suggestion id'),
      description: new TranslatableMarkup('The suggestion that was decided.'),
    ),
    'decision' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Decision'),
      description: new TranslatableMarkup('The decision on the suggestion now.'),
    ),
    'content_id' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Content id'),
      description: new TranslatableMarkup('The reviewed content item.'),
    ),
  ],
  permission: 'run reviews',
)]
final class DecideSuggestionTool extends ToolBase {

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
      $id = (int) $values['suggestion_id'];
      [$suggestion, $record, $entity] = $this->helper->loadSuggestion($id);
      if ($suggestion === NULL || $record === NULL || $entity === NULL) {
        throw new \InvalidArgumentException(sprintf(
          'No suggestion with id %d on content that still exists. Use ai_content_review_tools_get_content_review to list a content item\'s suggestions and their ids.',
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

      $decision = (string) $values['decision'];
      $suggestion->setDecision($decision, (int) $this->currentUser->id())->save();

      return ExecutableResult::success(
        new TranslatableMarkup('Suggestion @id on "@title" ([@field] "@suggested") is now @decision. The content itself was not changed@hint.', [
          '@id' => $id,
          '@title' => $entity->label(),
          '@field' => $suggestion->getTarget() ?? 'general',
          '@suggested' => $suggestion->getSuggestedValue() ?? '',
          '@decision' => $decision,
          '@hint' => $decision === 'accepted' && !$suggestion->isApplied() && $suggestion->getTarget() !== NULL
            ? '; to write it into the content, use ai_content_review_tools_apply_suggestion'
            : '',
        ]),
        ['suggestion_id' => $id, 'decision' => $suggestion->getDecision(), 'content_id' => (int) $entity->id()],
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
   * it the account must be able to edit the reviewed content, as the module's
   * own triage endpoint requires (InternalReviewRecord::canApplySuggestions()).
   * An unknown suggestion is left to doExecute(), which explains how to find
   * a valid id.
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
