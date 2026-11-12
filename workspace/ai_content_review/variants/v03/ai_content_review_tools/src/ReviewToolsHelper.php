<?php

declare(strict_types=1);

namespace Drupal\ai_content_review_tools;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_content_review\Entity\InternalReviewRecordInterface;
use Drupal\ai_content_review\Entity\InternalReviewRuleInterface;
use Drupal\ai_content_review\Entity\ReviewRecordInterface;
use Drupal\ai_content_review\Entity\ReviewRuleInterface;
use Drupal\ai_content_review\Entity\SuggestionInterface;
use Drupal\ai_content_review\GradeEvaluator;
use Drupal\ai_content_review\InputHasherInterface;
use Drupal\ai_content_review\ReviewRecordLoaderInterface;
use Drupal\ai_content_review\ReviewRuleLoaderInterface;

/**
 * Shared lookups and read models for the AI Content Review tools.
 *
 * The tools take what an editor would say (a rule's label, a criterion's
 * name, an agent's label, a content title) as well as ids. Every lookup here
 * either resolves to exactly one thing or throws an \InvalidArgumentException
 * whose message lists the valid values, so the calling agent can recover
 * without guessing.
 */
class ReviewToolsHelper {

  /**
   * The function-call tool every review agent must carry.
   */
  public const RESULT_TOOL = 'ai_content_review:content_review_result';

  /**
   * The criterion plugin the module ships and the tools configure.
   */
  public const CRITERION_PLUGIN = 'agent_based';

  /**
   * Grades as the tools report them.
   */
  public const GRADES = ['pass', 'warn', 'fail', 'not reviewed'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly AiAgentManager $agentManager,
    private readonly PluginManagerInterface $criterionManager,
    private readonly InputHasherInterface $inputHasher,
    private readonly ReviewRuleLoaderInterface $ruleLoader,
    private readonly ReviewRecordLoaderInterface $recordLoader,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * All review rules, sorted by label.
   *
   * @return \Drupal\ai_content_review\Entity\ReviewRuleInterface[]
   *   The rules keyed by id.
   */
  public function rules(): array {
    $rules = $this->entityTypeManager->getStorage('review_rule')->loadMultiple();
    uasort($rules, static fn($a, $b) => strcasecmp((string) $a->label(), (string) $b->label()));
    return $rules;
  }

  /**
   * Finds one rule by machine name or label.
   *
   * @throws \InvalidArgumentException
   *   When nothing or more than one rule matches; the message lists the rules.
   */
  public function findRule(string $ref): ReviewRuleInterface {
    $rules = $this->rules();
    $ref = trim($ref);
    if (isset($rules[$ref])) {
      return $rules[$ref];
    }
    $match = $this->match(
      $rules,
      $ref,
      static fn(ReviewRuleInterface $r): array => [(string) $r->label(), (string) $r->id()],
    );
    if ($match !== NULL) {
      return $match;
    }
    $list = array_map(static fn(ReviewRuleInterface $r): string => sprintf('"%s" (%s)', $r->label(), $r->id()), $rules);
    throw new \InvalidArgumentException(sprintf(
      'No review rule matches "%s". Existing rules: %s.',
      $ref,
      $list ? implode(', ', $list) : 'none yet',
    ));
  }

  /**
   * Finds a criterion of a rule by name, acronym or 1-based position.
   *
   * @return int
   *   The 0-based index into the rule's criteria.
   *
   * @throws \InvalidArgumentException
   *   When nothing or more than one criterion matches.
   */
  public function findCriterion(ReviewRuleInterface $rule, string $ref): int {
    $criteria = $rule->getCriteria();
    $ref = trim($ref);
    if (ctype_digit($ref) && isset($criteria[(int) $ref - 1])) {
      return (int) $ref - 1;
    }
    $index = $this->match(
      $criteria,
      $ref,
      static fn(array $c): array => [(string) ($c['label'] ?? ''), (string) ($c['acronym'] ?? '')],
      TRUE,
    );
    if ($index !== NULL) {
      return (int) $index;
    }
    throw new \InvalidArgumentException(sprintf(
      'Rule "%s" has no criterion matching "%s". Its criteria: %s.',
      $rule->label(),
      $ref,
      $this->criteriaList($rule),
    ));
  }

  /**
   * The rule's criteria as "1. Name, 2. Name".
   */
  public function criteriaList(ReviewRuleInterface $rule): string {
    $names = [];
    foreach (array_values($rule->getCriteria()) as $i => $c) {
      $names[] = sprintf('%d. "%s"', $i + 1, $c['label'] ?? '');
    }
    return $names ? implode(', ', $names) : 'none';
  }

  /**
   * The AI agents a criterion may use: those carrying the result tool.
   *
   * @return array<string, string>
   *   Labels keyed by agent id, sorted by label.
   */
  public function eligibleAgents(): array {
    $agents = [];
    foreach ($this->agentManager->getAgentsByTool(self::RESULT_TOOL) as $id => $definition) {
      $agents[$id] = (string) ($definition['label'] ?? $id);
    }
    asort($agents, SORT_NATURAL | SORT_FLAG_CASE);
    return $agents;
  }

  /**
   * Finds an eligible agent by id or label.
   *
   * @return string
   *   The agent id.
   *
   * @throws \InvalidArgumentException
   *   When the agent does not exist or cannot report review results.
   */
  public function findAgent(string $ref): string {
    $agents = $this->eligibleAgents();
    $ref = trim($ref);
    if (isset($agents[$ref])) {
      return $ref;
    }
    $id = $this->match($agents, $ref, static fn(string $label): array => [$label]);
    if ($id !== NULL) {
      return (string) array_search($id, $agents, TRUE);
    }
    $list = [];
    foreach ($agents as $agent_id => $label) {
      $list[] = sprintf('"%s" (%s)', $label, $agent_id);
    }
    throw new \InvalidArgumentException(sprintf(
      'No AI agent that can score reviews matches "%s". A criterion can only use an agent with the "AI Content Review Result" tool. Agents you can use: %s.',
      $ref,
      $list ? implode(', ', $list) : 'none (create one under AI Agents first)',
    ));
  }

  /**
   * Resolves content type names or labels to bundle machine names.
   *
   * @param string $entity_type_id
   *   The entity type, e.g. "node".
   * @param string[] $refs
   *   Bundle machine names or labels. Empty means all bundles.
   *
   * @return string[]
   *   Bundle machine names.
   *
   * @throws \InvalidArgumentException
   *   When the entity type or a bundle does not exist.
   */
  public function resolveBundles(string $entity_type_id, array $refs): array {
    $this->assertContentEntityType($entity_type_id);
    $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);
    $labels = array_map(static fn(array $info): string => (string) ($info['label'] ?? ''), $bundles);
    $resolved = [];
    foreach ($refs as $ref) {
      $ref = trim((string) $ref);
      if ($ref === '') {
        continue;
      }
      if (isset($bundles[$ref])) {
        $resolved[] = $ref;
        continue;
      }
      $hit = $this->match($labels, $ref, static fn(string $label): array => [$label]);
      if ($hit === NULL) {
        $list = [];
        foreach ($labels as $id => $label) {
          $list[] = sprintf('"%s" (%s)', $label, $id);
        }
        throw new \InvalidArgumentException(sprintf(
          'No %s type matches "%s". Available: %s.',
          $entity_type_id,
          $ref,
          implode(', ', $list),
        ));
      }
      $resolved[] = (string) array_search($hit, $labels, TRUE);
    }
    return array_values(array_unique($resolved));
  }

  /**
   * Throws unless the entity type exists and is a content entity type.
   */
  public function assertContentEntityType(string $entity_type_id): void {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($definition === NULL || !$definition->entityClassImplements(ContentEntityInterface::class)) {
      throw new \InvalidArgumentException(sprintf(
        '"%s" is not a content entity type. Use e.g. "node" for content.',
        $entity_type_id,
      ));
    }
  }

  /**
   * Builds a criterion definition the way the module's rule form stores it.
   *
   * @return array<string, mixed>
   *   The criterion, ready to go into ReviewRule::$criteria.
   */
  public function buildCriterion(string $name, string $agent_id, string $guidelines, int $pass, ?int $warn): array {
    return [
      'label' => trim($name),
      'plugin_id' => self::CRITERION_PLUGIN,
      'short_description' => NULL,
      'acronym' => NULL,
      'badge_color' => NULL,
      'pass_threshold' => $pass,
      'warn_threshold' => $warn,
      'configuration' => $this->criterionConfiguration([
        'agent_id' => $agent_id,
        'prompt_template' => trim($guidelines),
      ]),
    ];
  }

  /**
   * Normalizes a criterion's configuration through the criterion plugin.
   *
   * Mirrors AgentBasedCriterion::submitConfigurationForm(): defaults merged
   * in, then only the schema-defined keys kept. The result is what gets
   * hashed, so it must match what the module's own form would store.
   *
   * @param array<string, mixed> $configuration
   *   The values to set.
   *
   * @return array<string, mixed>
   *   The full configuration.
   */
  public function criterionConfiguration(array $configuration): array {
    /** @var \Drupal\Component\Plugin\ConfigurableInterface $plugin */
    $plugin = $this->criterionManager->createInstance(self::CRITERION_PLUGIN, $configuration);
    $defaults = $plugin->defaultConfiguration();
    return array_intersect_key($plugin->getConfiguration() + $defaults, $defaults);
  }

  /**
   * Checks the pass and warn scores the way the module's rule form does.
   *
   * @throws \InvalidArgumentException
   *   When a score is out of range or warn is not below pass.
   */
  public function assertScores(?int $pass, ?int $warn): void {
    if ($pass === NULL || $pass < 0 || $pass > 100) {
      throw new \InvalidArgumentException('The passing score must be between 0 and 100.');
    }
    if ($warn !== NULL) {
      if ($warn < 0 || $warn > 100) {
        throw new \InvalidArgumentException('The warning score must be between 0 and 100.');
      }
      if ($warn >= $pass) {
        throw new \InvalidArgumentException(sprintf(
          'The warning score (%d) must be lower than the passing score (%d): scores from the warning score up to the passing score warn instead of failing.',
          $warn,
          $pass,
        ));
      }
    }
  }

  /**
   * Validates a rule against the module's config schema and constraints.
   *
   * @return string[]
   *   Readable violation messages; empty when the rule is valid.
   */
  public function violations(ReviewRuleInterface $rule): array {
    $messages = [];
    foreach ($rule->getTypedData()->validate() as $violation) {
      $messages[] = trim($violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage()), ': ');
    }
    return $messages;
  }

  /**
   * A rule as the tools report it.
   *
   * @return array<string, mixed>
   *   Plain values only.
   */
  public function describeRule(ReviewRuleInterface $rule): array {
    $agents = $this->eligibleAgents();
    $criteria = [];
    foreach (array_values($rule->getCriteria()) as $i => $c) {
      $agent_id = (string) ($c['configuration']['agent_id'] ?? '');
      $criteria[] = [
        'position' => $i + 1,
        'name' => (string) ($c['label'] ?? ''),
        'pass_score' => $c['pass_threshold'] ?? NULL,
        'warn_score' => $c['warn_threshold'] ?? NULL,
        'agent' => $agent_id,
        'agent_label' => $agents[$agent_id] ?? $agent_id,
        'guidelines' => (string) ($c['configuration']['prompt_template'] ?? ''),
      ];
    }
    return [
      'id' => (string) $rule->id(),
      'label' => (string) $rule->label(),
      'enabled' => (bool) $rule->status(),
      'entity_type' => $rule instanceof InternalReviewRuleInterface ? $rule->getReviewedEntityTypeId() : '',
      'content_types' => $rule instanceof InternalReviewRuleInterface ? array_values($rule->getBundles()) : [],
      'criteria' => $criteria,
    ];
  }

  /**
   * One line per criterion, for result messages.
   */
  public function describeCriterion(array $c, int $position): string {
    return sprintf(
      '%d. "%s" (pass %s%s, agent %s)',
      $position,
      $c['label'] ?? '',
      $c['pass_threshold'] ?? 'not set',
      isset($c['warn_threshold']) ? ', warn ' . $c['warn_threshold'] : '',
      $c['configuration']['agent_id'] ?? '?',
    );
  }

  /**
   * Finds one content entity by id or title that the account may view.
   *
   * @throws \InvalidArgumentException
   *   When nothing or more than one item matches; lists close matches.
   */
  public function findContent(string $entity_type_id, string $ref, AccountInterface $account): ContentEntityInterface {
    $this->assertContentEntityType($entity_type_id);
    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $ref = trim($ref);

    if (ctype_digit($ref)) {
      $entity = $storage->load($ref);
      if ($entity instanceof ContentEntityInterface && $entity->access('view', $account)) {
        return $entity;
      }
    }

    $label_key = $definition->getKey('label');
    if (!$label_key) {
      throw new \InvalidArgumentException(sprintf('No %s with id %s that you can view.', $entity_type_id, $ref));
    }
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition($label_key, $this->escapeLike($ref), 'LIKE')
      ->range(0, 10)
      ->execute();
    if (!$ids) {
      $ids = $storage->getQuery()->accessCheck(TRUE)
        ->condition($label_key, '%' . $this->escapeLike($ref) . '%', 'LIKE')
        ->range(0, 10)
        ->execute();
    }
    $entities = array_filter(
      $ids ? $storage->loadMultiple($ids) : [],
      static fn($e) => $e instanceof ContentEntityInterface && $e->access('view', $account),
    );
    if (count($entities) === 1) {
      return reset($entities);
    }
    if ($entities) {
      $list = array_map(static fn($e) => sprintf('"%s" (id %s)', $e->label(), $e->id()), $entities);
      throw new \InvalidArgumentException(sprintf(
        'More than one %s matches "%s": %s. Pass the id instead.',
        $entity_type_id,
        $ref,
        implode(', ', $list),
      ));
    }
    throw new \InvalidArgumentException(sprintf(
      'No %s titled "%s" that you can view. Check the title, or use %s to see which content has review results.',
      $entity_type_id,
      $ref,
      'ai_content_review_tools_list_review_results',
    ));
  }

  /**
   * The latest result per criterion for each subject, graded.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $entities
   *   Content entities of one entity type, keyed by id.
   * @param bool $with_details
   *   Whether to include explanation and suggestions.
   *
   * @return array<int|string, list<array<string, mixed>>>
   *   Rows keyed by entity id, one row per criterion of every enabled rule
   *   that applies to the entity's bundle.
   */
  public function results(array $entities, bool $with_details = FALSE): array {
    if (!$entities) {
      return [];
    }
    $latest = $this->recordLoader->loadLatestForSubjects($entities);
    $rows = [];
    foreach ($entities as $key => $entity) {
      $rows[$key] = [];
      foreach ($this->ruleLoader->loadForEntity($entity) as $rule) {
        foreach (array_values($rule->getCriteria()) as $i => $c) {
          $hash = $this->inputHasher->hash($c['configuration'] ?? []);
          $record = $latest[$key][$hash] ?? NULL;
          $rows[$key][] = $this->resultRow($entity, $rule, $c, $i + 1, $record, $with_details);
        }
      }
    }
    return $rows;
  }

  /**
   * One graded row for an entity and criterion.
   *
   * @return array<string, mixed>
   *   Plain values only.
   */
  private function resultRow(ContentEntityInterface $entity, ReviewRuleInterface $rule, array $c, int $position, ?ReviewRecordInterface $record, bool $with_details): array {
    [$pass, $warn] = GradeEvaluator::thresholdsFor($c);
    $status = $record?->getStatus() ?? 'not reviewed';
    $score = $record?->getScore();
    $score = $score === NULL ? NULL : (int) $score;
    $grade = $status === ReviewRecordInterface::STATUS_COMPLETE
      ? $this->gradeName(GradeEvaluator::grade($score, $pass, $warn))
      : 'not reviewed';
    $row = [
      'content_id' => (int) $entity->id(),
      'title' => (string) $entity->label(),
      'content_type' => $entity->bundle(),
      'published' => $entity instanceof EntityPublishedInterface ? $entity->isPublished() : TRUE,
      'rule' => (string) $rule->label(),
      'criterion' => (string) ($c['label'] ?? ''),
      'criterion_position' => $position,
      'score' => $score,
      'grade' => $grade,
      'pass_score' => $pass,
      'warn_score' => $warn,
      'status' => $status,
      'reviewed' => $record && $record->getReviewedAt() ? $this->dateFormatter->format((int) $record->getReviewedAt(), 'custom', 'Y-m-d H:i') : NULL,
    ];
    if ($with_details) {
      $row['severity'] = $record?->getSeverity();
      $row['explanation'] = $record?->getExplanation() ?? $record?->getErrorMessage();
      $row['suggestions'] = $record ? array_map([$this, 'describeSuggestion'], array_values($record->getSuggestions())) : [];
    }
    return $row;
  }

  /**
   * Maps a GradeEvaluator grade to the tools' wording.
   */
  public function gradeName(string $grade): string {
    return match ($grade) {
      GradeEvaluator::GRADE_PASS => 'pass',
      GradeEvaluator::GRADE_WARN => 'warn',
      GradeEvaluator::GRADE_FAIL => 'fail',
      default => 'not reviewed',
    };
  }

  /**
   * A suggestion as the tools report it.
   *
   * @return array<string, mixed>
   *   Plain values only.
   */
  public function describeSuggestion(SuggestionInterface $s): array {
    return [
      'id' => (int) $s->id(),
      'type' => $s->getType(),
      'field' => $s->getTarget(),
      'current_text' => $s->getOriginalValue(),
      'suggested_text' => $s->getSuggestedValue(),
      'rationale' => $s->getRationale(),
      'decision' => $s->getDecision(),
      'applied' => $s->isApplied(),
      'can_apply' => $s->getTarget() !== NULL && $s->getSuggestedValue() !== NULL && $s->getType() !== 'general_recommendation',
    ];
  }

  /**
   * Loads a suggestion with its record and the reviewed entity (default rev).
   *
   * @return array{0: \Drupal\ai_content_review\Entity\SuggestionInterface|null, 1: \Drupal\ai_content_review\Entity\InternalReviewRecordInterface|null, 2: \Drupal\Core\Entity\ContentEntityInterface|null}
   *   The suggestion, its record and the entity as it stands now; NULLs for
   *   whatever does not exist.
   */
  public function loadSuggestion(int $suggestion_id): array {
    $suggestion = $this->entityTypeManager->getStorage('suggestion')->load($suggestion_id);
    if (!$suggestion instanceof SuggestionInterface) {
      return [NULL, NULL, NULL];
    }
    $record = $suggestion->getReviewRecord();
    if (!$record instanceof InternalReviewRecordInterface) {
      return [$suggestion, NULL, NULL];
    }
    $entity = NULL;
    $entity_type_id = $record->getReviewedEntityTypeId();
    if ($entity_type_id !== '' && $this->entityTypeManager->hasDefinition($entity_type_id)) {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($record->getReviewedEntityId());
    }
    return [$suggestion, $record, $entity instanceof ContentEntityInterface ? $entity : NULL];
  }

  /**
   * Picks the single item whose name matches: exact, then unique substring.
   *
   * @param array $items
   *   The candidates.
   * @param string $ref
   *   What the caller typed.
   * @param callable $names
   *   Returns the names an item answers to.
   * @param bool $return_key
   *   Return the matching key instead of the item.
   *
   * @return mixed
   *   The item (or key), or NULL when none or several match.
   */
  private function match(array $items, string $ref, callable $names, bool $return_key = FALSE): mixed {
    $needle = mb_strtolower(trim($ref));
    if ($needle === '') {
      return NULL;
    }
    foreach ([TRUE, FALSE] as $exact) {
      $hits = [];
      foreach ($items as $key => $item) {
        foreach ($names($item) as $name) {
          $name = mb_strtolower(trim((string) $name));
          if ($name === '') {
            continue;
          }
          if ($exact ? $name === $needle : str_contains($name, $needle)) {
            $hits[$key] = $item;
            break;
          }
        }
      }
      if (count($hits) === 1) {
        return $return_key ? array_key_first($hits) : reset($hits);
      }
      if (count($hits) > 1) {
        return NULL;
      }
    }
    return NULL;
  }

  /**
   * Escapes LIKE wildcards in user input.
   */
  private function escapeLike(string $value): string {
    return addcslashes($value, '\\%_');
  }

}
