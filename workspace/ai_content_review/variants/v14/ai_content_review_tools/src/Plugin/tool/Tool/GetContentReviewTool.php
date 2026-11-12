<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Get Content Review tool.
 */
#[Tool(
  id: 'ai_content_review_tools_get_content_review',
  label: new TranslatableMarkup('Get Content Review'),
  description: new TranslatableMarkup('Shows the latest AI review of one content item: score, grade and explanation per criterion, and the suggestions with their ids, target field, text, decision and whether they were applied. Use it to find suggestion ids.'),
  operation: ToolOperation::Read,
  destructive: FALSE,
  input_definitions: [
    'content' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content'),
      description: new TranslatableMarkup('The content item, by title or id, e.g. "Summer sale" or "12".'),
      required: TRUE,
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
    'content' => new MapOutputDefinition(
      label: new TranslatableMarkup('Content'),
      description: new TranslatableMarkup('The content item: id, title, content_type, published.'),
    ),
    'criteria' => new ListOutputDefinition(
      label: new TranslatableMarkup('Criteria'),
      description: new TranslatableMarkup('One entry per criterion: rule, criterion, score, grade, pass_score, warn_score, status, reviewed, severity, explanation and suggestions (id, type, field, current_text, suggested_text, rationale, decision, applied, can_apply).'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Criterion result'),
        description: new TranslatableMarkup('The latest result for one criterion.'),
      ),
    ),
  ],
  permission: 'view review records',
)]
final class GetContentReviewTool extends ToolBase {

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
      $entity_type = trim((string) ($values['entity_type'] ?? '')) ?: 'node';
      // Only content the current user may view is found at all.
      $ref = trim((string) $values['content']);
      $entity = $this->helper->findContent($entity_type, $ref, $this->currentUser, TRUE);
      // Say so when the item shown is not exactly the one asked for.
      $closest = $ref !== (string) $entity->id() && mb_strtolower($ref) !== mb_strtolower(trim((string) $entity->label()))
        ? sprintf(', the closest match to "%s" (no content has that exact title)', $ref)
        : '';
      $rows = $this->helper->results([$entity->id() => $entity], TRUE)[$entity->id()] ?? [];
      $content = [
        'id' => (int) $entity->id(),
        'title' => (string) $entity->label(),
        'content_type' => $entity->bundle(),
        'published' => $entity instanceof EntityPublishedInterface ? $entity->isPublished() : TRUE,
      ];
      $criteria = array_map(static function (array $row): array {
        unset($row['content_id'], $row['title'], $row['content_type'], $row['published']);
        return $row;
      }, $rows);

      if (!$criteria) {
        return ExecutableResult::success(
          new TranslatableMarkup('"@title" (@type @id)@closest is not covered by any enabled review rule, so it has no AI review.', [
            '@title' => $entity->label(),
            '@type' => $entity->bundle(),
            '@id' => $entity->id(),
            '@closest' => $closest,
          ]),
          ['content' => $content, 'criteria' => []],
        );
      }

      $lines = [];
      $has_suggestions = FALSE;
      foreach ($criteria as $c) {
        $has_suggestions = $has_suggestions || !empty($c['suggestions']);
        $line = sprintf('- %s (rule "%s"): ', $c['criterion'], $c['rule']);
        $line .= $c['grade'] === 'not reviewed'
          ? ($c['status'] === 'not reviewed' ? 'not reviewed yet.' : sprintf('not reviewed (%s).', $c['status']))
          : sprintf('score %d -> %s (pass %s%s), reviewed %s.', $c['score'], $c['grade'], $c['pass_score'], $c['warn_score'] !== NULL ? ', warn ' . $c['warn_score'] : '', $c['reviewed']);
        if (!empty($c['explanation'])) {
          $line .= ' Explanation: ' . $c['explanation'];
        }
        $lines[] = $line;
        foreach ($c['suggestions'] as $s) {
          $lines[] = sprintf(
            '    Suggestion id %d [%s]: %s%s. Why: %s. Decision: %s; %s.',
            $s['id'],
            $s['field'] ?? 'general',
            $s['current_text'] !== NULL ? sprintf('"%s" -> ', $s['current_text']) : '',
            sprintf('"%s"', $s['suggested_text'] ?? ''),
            rtrim((string) $s['rationale'], '. '),
            $s['decision'],
            $s['applied'] ? 'applied' : ($s['can_apply'] ? 'not applied' : 'cannot be applied (general recommendation)'),
          );
        }
      }
      if ($has_suggestions) {
        $lines[] = 'To accept a suggestion the user approves of or likes (also if they will use it later) or to ignore one, use ai_content_review_tools_decide_suggestion; the content stays unchanged. Use ai_content_review_tools_apply_suggestion only to write a suggestion into the content now.';
      }

      return ExecutableResult::success(
        new TranslatableMarkup("AI review of @title (@type @id)@closest:\n@lines", [
          '@title' => '"' . $entity->label() . '"',
          '@type' => $entity->bundle(),
          '@id' => $entity->id(),
          '@closest' => $closest,
          '@lines' => implode("\n", $lines),
        ]),
        ['content' => $content, 'criteria' => $criteria],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

}
