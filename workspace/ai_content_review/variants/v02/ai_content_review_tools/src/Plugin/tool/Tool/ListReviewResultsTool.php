<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools\Plugin\tool\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_content_review\Entity\InternalReviewRuleInterface;
use Drupal\ai_content_review\ReviewRuleLoaderInterface;
use Drupal\ai_content_review_tools\ReviewToolsHelper;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the List Review Results tool.
 */
#[Tool(
  id: 'ai_content_review_tools_list_review_results',
  label: new TranslatableMarkup('List Review Results'),
  description: new TranslatableMarkup('Lists the latest AI review result of each content item for each criterion of the enabled review rules, already graded against the passing and warning scores of the criterion: pass, warn, fail or not reviewed. Filter by criterion, grade, content type or title to answer questions like "which articles fail Tone & voice?". For one item\'s explanations and suggestions use ai_content_review_tools_get_content_review.'),
  operation: ToolOperation::Read,
  destructive: FALSE,
  input_definitions: [
    'criterion' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Criterion'),
      description: new TranslatableMarkup('Optional. Only results for this criterion, by name, e.g. "Tone & voice".'),
      required: FALSE,
    ),
    'grade' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Grade'),
      description: new TranslatableMarkup('Optional. Only results with this grade: "pass", "warn", "fail" or "not reviewed". "fail" means below the warning score (or below the passing score when there is no warning score).'),
      required: FALSE,
      constraints: ['Choice' => ['choices' => ['pass', 'warn', 'fail', 'not reviewed']]],
    ),
    'content_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content type'),
      description: new TranslatableMarkup('Optional. Only content of this type, by name or machine name, e.g. "article".'),
      required: FALSE,
    ),
    'title' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title contains'),
      description: new TranslatableMarkup('Optional. Only content whose title contains this text.'),
      required: FALSE,
    ),
    'limit' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Maximum number of results to return (1-200).'),
      required: FALSE,
      default_value: 50,
      constraints: ['Range' => ['min' => 1, 'max' => 200]],
    ),
  ],
  output_definitions: [
    'results' => new ListOutputDefinition(
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('One row per content item and criterion: content_id, title, content_type, published, rule, criterion, score, grade, pass_score, warn_score, status, reviewed.'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Result'),
        description: new TranslatableMarkup('One graded result.'),
      ),
    ),
    'total' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Total'),
      description: new TranslatableMarkup('How many results match the filters (may exceed the returned rows).'),
    ),
    'counts' => new MapOutputDefinition(
      label: new TranslatableMarkup('Counts'),
      description: new TranslatableMarkup('Results per grade before the grade filter: pass, warn, fail, not reviewed.'),
    ),
  ],
  permission: 'view review records',
)]
final class ListReviewResultsTool extends ToolBase {

  /**
   * How many content items one call examines per entity type at most.
   */
  private const MAX_ITEMS = 1000;

  /**
   * The shared review tool helper.
   */
  protected ReviewToolsHelper $helper;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The module's rule loader.
   */
  protected ReviewRuleLoaderInterface $ruleLoader;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->helper = $container->get('ai_content_review_tools.helper');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->ruleLoader = $container->get('ai_content_review.rule_loader');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $rules = array_filter(
        $this->ruleLoader->loadForRecordType('internal'),
        static fn($rule) => $rule instanceof InternalReviewRuleInterface,
      );
      if (!$rules) {
        return ExecutableResult::success(
          new TranslatableMarkup('There are no enabled review rules, so there are no review results.'),
          ['results' => [], 'total' => 0, 'counts' => $this->emptyCounts()],
        );
      }

      // Which criterion names the filter means, matched across all rules.
      $criterion_names = NULL;
      $criterion_ref = trim((string) ($values['criterion'] ?? ''));
      if ($criterion_ref !== '') {
        $criterion_names = $this->matchCriteria($rules, $criterion_ref);
      }

      // Group the rules by the entity type they review.
      $by_type = [];
      foreach ($rules as $rule) {
        $by_type[$rule->getReviewedEntityTypeId()][] = $rule;
      }

      $type_ref = trim((string) ($values['content_type'] ?? ''));
      $title = trim((string) ($values['title'] ?? ''));
      $rows = [];
      $type_matched = $type_ref === '';
      foreach ($by_type as $entity_type_id => $type_rules) {
        $bundles = NULL;
        if ($type_ref !== '') {
          try {
            $bundles = $this->helper->resolveBundles($entity_type_id, [$type_ref]);
            $type_matched = TRUE;
          }
          catch (\InvalidArgumentException $e) {
            $last_type_error = $e->getMessage();
            continue;
          }
        }
        foreach ($this->rowsFor($entity_type_id, $type_rules, $bundles, $title) as $row) {
          if ($criterion_names !== NULL && !in_array(mb_strtolower($row['criterion']), $criterion_names, TRUE)) {
            continue;
          }
          $rows[] = $row;
        }
      }
      if (!$type_matched) {
        throw new \InvalidArgumentException($last_type_error ?? sprintf('No content type matches "%s".', $type_ref));
      }

      $counts = $this->emptyCounts();
      foreach ($rows as $row) {
        $counts[$row['grade']]++;
      }
      $grade = trim((string) ($values['grade'] ?? ''));
      if ($grade !== '') {
        $rows = array_values(array_filter($rows, static fn(array $row) => $row['grade'] === $grade));
      }
      $total = count($rows);
      $limit = (int) ($values['limit'] ?? 50);
      $shown = array_slice($rows, 0, $limit);

      $filters = [];
      if ($criterion_ref !== '') {
        $filters[] = sprintf('criterion "%s"', $criterion_ref);
      }
      if ($grade !== '') {
        $filters[] = sprintf('grade %s', $grade);
      }
      if ($type_ref !== '') {
        $filters[] = sprintf('content type %s', $type_ref);
      }
      if ($title !== '') {
        $filters[] = sprintf('title containing "%s"', $title);
      }
      $lines = array_map(static fn(array $r) => sprintf(
        '- "%s" (%s %d): %s = %s%s (pass %s%s)',
        $r['title'],
        $r['content_type'],
        $r['content_id'],
        $r['criterion'],
        $r['score'] ?? '-',
        ' -> ' . $r['grade'],
        $r['pass_score'] ?? 'not set',
        $r['warn_score'] !== NULL ? ', warn ' . $r['warn_score'] : '',
      ), $shown);

      return ExecutableResult::success(
        new TranslatableMarkup("@total result(s)@filters. All grades for these filters: @counts.\n@lines@more", [
          '@total' => $total,
          '@filters' => $filters ? ' for ' . implode(', ', $filters) : '',
          '@counts' => implode(', ', array_map(static fn($g, $n) => "$g $n", array_keys($counts), $counts)),
          '@lines' => implode("\n", $lines),
          '@more' => $total > count($shown) ? sprintf("\n... and %d more; narrow the filters or raise the limit.", $total - count($shown)) : '',
        ]),
        ['results' => $shown, 'total' => $total, 'counts' => $counts],
      );
    }
    catch (\Exception $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Failed: @message', ['@message' => $e->getMessage()]),
      );
    }
  }

  /**
   * The graded rows for the content one entity type's rules cover.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param \Drupal\ai_content_review\Entity\InternalReviewRuleInterface[] $rules
   *   Enabled rules for that entity type.
   * @param string[]|null $only_bundles
   *   Restrict to these bundles, or NULL for whatever the rules cover.
   * @param string $title
   *   Title substring filter, or ''.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  private function rowsFor(string $entity_type_id, array $rules, ?array $only_bundles, string $title): array {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return [];
    }
    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $storage = $this->entityTypeManager->getStorage($entity_type_id);

    // The bundles any rule covers; NULL when one rule covers every bundle.
    $covered = [];
    foreach ($rules as $rule) {
      if (!$rule->getBundles()) {
        $covered = NULL;
        break;
      }
      $covered = array_merge($covered, $rule->getBundles());
    }
    $bundles = $only_bundles;
    if ($covered !== NULL) {
      $bundles = $bundles === NULL ? $covered : array_values(array_intersect($bundles, $covered));
      if (!$bundles) {
        return [];
      }
    }

    $query = $storage->getQuery()->accessCheck(TRUE)
      ->sort($definition->getKey('id'))
      ->range(0, self::MAX_ITEMS);
    if ($bundles !== NULL && ($bundle_key = $definition->getKey('bundle'))) {
      $query->condition($bundle_key, $bundles, 'IN');
    }
    if ($title !== '' && ($label_key = $definition->getKey('label'))) {
      $query->condition($label_key, '%' . addcslashes($title, '\\%_') . '%', 'LIKE');
    }
    $ids = $query->execute();

    $rows = [];
    foreach (array_chunk($ids, 50) as $chunk) {
      $entities = array_filter(
        $storage->loadMultiple($chunk),
        fn($entity) => $entity->access('view', $this->currentUser),
      );
      foreach ($this->helper->results($entities) as $entity_rows) {
        array_push($rows, ...$entity_rows);
      }
    }
    return $rows;
  }

  /**
   * Lower-cased criterion names across the rules that match a reference.
   *
   * @return string[]
   *   The matching names.
   *
   * @throws \InvalidArgumentException
   *   When no criterion matches; lists the criteria.
   */
  private function matchCriteria(array $rules, string $ref): array {
    $all = [];
    foreach ($rules as $rule) {
      foreach ($rule->getCriteria() as $c) {
        $name = (string) ($c['label'] ?? '');
        $all[mb_strtolower($name)] = $name;
      }
    }
    $needle = mb_strtolower($ref);
    if (isset($all[$needle])) {
      return [$needle];
    }
    $hits = array_values(array_filter(array_keys($all), static fn(string $name) => str_contains($name, $needle) || str_contains($needle, $name)));
    if ($hits) {
      return $hits;
    }
    throw new \InvalidArgumentException(sprintf(
      'No criterion of an enabled rule matches "%s". Criteria: %s.',
      $ref,
      $all ? '"' . implode('", "', $all) . '"' : 'none',
    ));
  }

  /**
   * Zero counts for every grade.
   *
   * @return array<string, int>
   *   Counts keyed by grade.
   */
  private function emptyCounts(): array {
    return array_fill_keys(ReviewToolsHelper::GRADES, 0);
  }

}
