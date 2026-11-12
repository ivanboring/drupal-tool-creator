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
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Decide Suggestion tool.
 *
 * One call takes every decision of a request, so an agent sends them
 * together instead of one call per suggestion.
 */
#[Tool(
  id: 'ai_content_review_tools_decide_suggestion',
  label: new TranslatableMarkup('Decide Suggestion'),
  description: new TranslatableMarkup('Accepts or ignores AI review suggestions without changing the content, all in one call. Accepting puts a suggestion on the improvement plan, the list of changes the editor makes later. Use it when the user approves of a suggestion (also when they say it is good and they will use it later) or wants to ignore, dismiss or reject one. Only when the user wants the suggested text written into the content now, use ai_content_review_tools_apply_suggestion instead. Get suggestion ids from ai_content_review_tools_get_content_review.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  input_definitions: [
    'decisions' => new ListInputDefinition(
      label: new TranslatableMarkup('Decisions'),
      description: new TranslatableMarkup('One item per suggestion the user accepts or ignores, e.g. [{"suggestion_id": 1, "decision": "accepted"}, {"suggestion_id": 2, "decision": "ignored"}]. Leave out the suggestions the user leaves as they are ("leave the rest").'),
      required: TRUE,
      item_definition: new MapInputDefinition(
        label: new TranslatableMarkup('Decision'),
        description: new TranslatableMarkup('A suggestion and its decision.'),
        property_definitions: [
          'suggestion_id' => new InputDefinition(
            data_type: 'integer',
            label: new TranslatableMarkup('Suggestion id'),
            description: new TranslatableMarkup('Suggestion id, e.g. 2.'),
            required: TRUE,
          ),
          'decision' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Decision'),
            description: new TranslatableMarkup('"accepted" (puts it on the improvement plan): the user approves of the suggestion or says it is good, also if they will put it in later themselves (accepting never changes the content). "ignored": the user dismisses or rejects it (not for suggestions the user just leaves as they are; leave those out). "undecided": resets an earlier decision.'),
            required: TRUE,
            constraints: ['Choice' => ['choices' => ['accepted', 'ignored', 'undecided']]],
          ),
        ],
      ),
    ),
  ],
  output_definitions: [
    'decisions' => new ListOutputDefinition(
      label: new TranslatableMarkup('Decisions'),
      description: new TranslatableMarkup('Each decided suggestion: suggestion_id, decision, content_id.'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Decision'),
        description: new TranslatableMarkup('One suggestion and its decision now.'),
      ),
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
   * The requested decisions, keyed by suggestion id.
   *
   * @return array<int, string>
   *   The decision per suggestion id, in the order given.
   *
   * @throws \InvalidArgumentException
   *   When one suggestion is given two different decisions.
   */
  private function requested(array $values): array {
    $decisions = [];
    foreach ((array) ($values['decisions'] ?? []) as $item) {
      $id = (int) ($item['suggestion_id'] ?? 0);
      $decision = (string) ($item['decision'] ?? '');
      if (!in_array($decision, ['accepted', 'ignored', 'undecided'], TRUE)) {
        throw new \InvalidArgumentException(sprintf('Suggestion %d has decision "%s"; use "accepted", "ignored" or "undecided".', $id, $decision));
      }
      if (isset($decisions[$id]) && $decisions[$id] !== $decision) {
        throw new \InvalidArgumentException(sprintf('Suggestion %d is given both "%s" and "%s"; give each suggestion one decision.', $id, $decisions[$id], $decision));
      }
      $decisions[$id] = $decision;
    }
    return $decisions;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $requested = $this->requested($values);
      if ($requested === []) {
        throw new \InvalidArgumentException('No decisions given. Give one item per suggestion; get suggestion ids from ai_content_review_tools_get_content_review.');
      }

      // Check every suggestion before changing any, so a call either takes
      // every decision or none.
      $items = [];
      $problems = [];
      foreach ($requested as $id => $decision) {
        [$suggestion, $record, $entity] = $this->helper->loadSuggestion($id);
        if ($suggestion === NULL || $record === NULL || $entity === NULL) {
          $problems[] = sprintf('there is no suggestion with id %d on content that still exists', $id);
        }
        elseif (!$record->isCurrent()) {
          $problems[] = sprintf('suggestion %d belongs to an older review of "%s" (the criterion has been reviewed again since)', $id, $entity->label());
        }
        else {
          $items[] = [$id, $decision, $suggestion, $entity];
        }
      }
      if ($problems) {
        throw new \InvalidArgumentException('Nothing was decided: ' . implode('; ', $problems) . '. Use ai_content_review_tools_get_content_review for the current suggestions and their ids.');
      }

      $lines = [];
      $outputs = [];
      $hint = FALSE;
      foreach ($items as [$id, $decision, $suggestion, $entity]) {
        if ($suggestion->getDecision() === $decision) {
          $lines[] = sprintf('Suggestion %d on "%s" was already %s; nothing changed. This suggestion is done.', $id, $entity->label(), $decision);
        }
        else {
          $suggestion->setDecision($decision, (int) $this->currentUser->id())->save();
          $lines[] = sprintf('Suggestion %d on "%s" ([%s] "%s") is now %s%s.',
            $id,
            $entity->label(),
            $suggestion->getTarget() ?? 'general',
            $suggestion->getSuggestedValue() ?? '',
            $decision,
            $decision === 'accepted' ? ' and on the improvement plan' : '',
          );
          $hint = $hint || ($decision === 'accepted' && !$suggestion->isApplied() && $suggestion->getTarget() !== NULL);
        }
        $outputs[] = ['suggestion_id' => $id, 'decision' => $decision, 'content_id' => (int) $entity->id()];
      }
      $lines[] = 'The content itself was not changed' . ($hint ? '; to write an accepted suggestion into the content, use ai_content_review_tools_apply_suggestion' : '') . '.';

      return ExecutableResult::success(
        new TranslatableMarkup('@lines', ['@lines' => implode("\n", $lines)]),
        ['decisions' => $outputs],
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
   * it the account must be able to edit the reviewed content of every
   * suggestion in the call, as the module's own triage endpoint requires
   * (InternalReviewRecord::canApplySuggestions()); one suggestion on content
   * it may not edit denies the whole call. Unknown suggestions are left to
   * doExecute(), which explains how to find a valid id.
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowed()->setCacheMaxAge(0);
    foreach ((array) ($values['decisions'] ?? []) as $item) {
      [$suggestion, , $entity] = $this->helper->loadSuggestion((int) (is_array($item) ? ($item['suggestion_id'] ?? 0) : 0));
      if ($suggestion !== NULL && $entity !== NULL) {
        $access = $access->andIf($entity->access('update', $account, TRUE));
      }
    }
    return $return_as_object ? $access : $access->isAllowed();
  }

}
