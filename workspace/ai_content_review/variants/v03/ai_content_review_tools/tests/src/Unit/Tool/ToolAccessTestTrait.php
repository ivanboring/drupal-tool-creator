<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_review_tools\Unit\Tool;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolManager;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Builds tool plugins without a container and checks their access.
 *
 * The definition is read from the plugin's own #[Tool] attribute, so the
 * tests assert the permission the tool really declares.
 */
trait ToolAccessTestTrait {

  /**
   * Gives AccessResult the cache contexts manager it asserts tokens against.
   */
  protected function setUpAccessContainer(): void {
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * The tool definition declared by a plugin class.
   */
  protected function definitionOf(string $class): ToolDefinition {
    $attribute = (new \ReflectionClass($class))->getAttributes(Tool::class)[0]->newInstance();
    $attribute->setClass($class);
    return $attribute->get();
  }

  /**
   * Instantiates a tool directly, without the container.
   */
  protected function buildTool(string $class, AccountInterface $account): ToolBase {
    $definition = $this->definitionOf($class);
    return new $class(
      [],
      $definition->id(),
      $definition,
      $account,
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(LoggerChannelInterface::class),
    );
  }

  /**
   * An account holding exactly the given permissions.
   *
   * @param string[] $permissions
   *   The permissions.
   */
  protected function accountWith(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    $account->method('id')->willReturn(7);
    return $account;
  }

  /**
   * Whether the tool's declared permission lets the account through.
   *
   * This is the first half of ToolBase::access(), which then combines it with
   * checkAccess().
   */
  protected function permissionAllows(string $class, AccountInterface $account): bool {
    return ToolManager::checkPermission($this->definitionOf($class), $account)->isAllowed();
  }

  /**
   * Invokes the protected checkAccess() on a tool.
   */
  protected function invokeCheckAccess(ToolBase $tool, array $values, AccountInterface $account): bool {
    $method = new \ReflectionMethod($tool, 'checkAccess');
    return $method->invoke($tool, $values, $account, FALSE);
  }

}
