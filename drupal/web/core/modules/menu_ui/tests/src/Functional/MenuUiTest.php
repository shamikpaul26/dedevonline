<?php

declare(strict_types=1);

namespace Drupal\Tests\menu_ui\Functional;

use Drupal\block\Entity\Block;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Url;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\system\MenuStorage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\menu_ui\Traits\MenuUiTrait;

/**
 * Tests the menu UI.
 *
 * This test adds a custom menu, adds menu links to the custom menu and the
 * Tools menu, checks their data, and deletes them using the UI.
 *
 * @group menu_ui
 */
class MenuUiTest extends BrowserTestBase {

  use MenuUiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'contextual',
    'help',
    'menu_link_content',
    'menu_ui',
    'node',
    'path',
    'test_page_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with administration rights.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * An authenticated user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $authenticatedUser;

  /**
   * Array of placed menu blocks keyed by block ID.
   *
   * @var array
   */
  protected $blockPlacements;

  /**
   * A test menu.
   *
   * @var \Drupal\system\Entity\Menu
   */
  protected $menu;

  /**
   * An array of test menu links.
   *
   * @var \Drupal\menu_link_content\MenuLinkContentInterface[]
   */
  protected $items;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalPlaceBlock('page_title_block');
    $this->drupalPlaceBlock('system_menu_block:main');
    $this->drupalPlaceBlock('local_actions_block', [
      'region' => 'content',
      'weight' => -100,
    ]);

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    // Create users.
    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
      'administer blocks',
      'administer menu',
      'create article content',
    ]);
    $this->authenticatedUser = $this->drupalCreateUser([]);
  }

  /**
   * Tests menu functionality using the admin and user interfaces.
   */
  public function testMenuAdministration(): void {
    // Log in the user.
    $this->drupalLogin($this->adminUser);
    $this->items = [];

    $this->menu = $this->addCustomMenu();
    $this->doMenuTests();
    $this->doTestMenuBlock();
    $this->addInvalidMenuLink();
    $this->addCustomMenuCRUD();

    // Verify that the menu links rebuild is idempotent and leaves the same
    // number of links in the table.
    /** @var \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager */
    $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
    $before_count = $menu_link_manager->countMenuLinks(NULL);
    $menu_link_manager->rebuild();
    $after_count = $menu_link_manager->countMenuLinks(NULL);
    $this->assertSame($before_count, $after_count, 'MenuLinkManager::rebuild() does not add more links');
    // Do standard user tests.
    // Log in the user.
    $this->drupalLogin($this->authenticatedUser);
    $this->verifyAccess(403);

    foreach ($this->items as $item) {
      // Menu link URIs are stored as 'internal:/node/$nid'.
      $node = Node::load(str_replace('internal:/node/', '', $item->link->uri));
      $this->verifyMenuLink($item, $node);
    }

    // Log in the administrator.
    $this->drupalLogin($this->adminUser);

    // Verify delete link exists and reset link does not exist.
    $this->drupalGet('admin/structure/menu/manage/' . $this->menu->id());
    $this->assertSession()->linkByHrefExists(Url::fromRoute('entity.menu_link_content.delete_form', ['menu_link_content' => $this->items[0]->id()])->toString());
    $this->assertSession()->linkByHrefNotExists(Url::fromRoute('menu_ui.link_reset', ['menu_link_plugin' => $this->items[0]->getPluginId()])->toString());
    // Check delete and reset access.
    $this->drupalGet('admin/structure/menu/item/' . $this->items[0]->id() . '/delete');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('admin/structure/menu/link/' . $this->items[0]->getPluginId() . '/reset');
    $this->assertSession()->statusCodeEquals(403);

    // Delete menu links.
    foreach ($this->items as $item) {
      $this->deleteMenuLink($item);
    }

    // Delete custom menu.
    $this->deleteCustomMenu();

    // Modify and reset a standard menu link.
    $instance = $this->getStandardMenuLink();
    $old_weight = $instance->getWeight();
    // Edit the static menu link.
    $edit = [];
    $edit['weight'] = 10;
    $id = $instance->getPluginId();
    $this->drupalGet("admin/structure/menu/link/{$id}/edit");
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The menu link has been saved.');
    $menu_link_manager->resetDefinitions();

    $instance = $menu_link_manager->createInstance($instance->getPluginId());
    $this->assertEquals($edit['weight'], $instance->getWeight(), 'Saving an existing link updates the weight.');
    $this->resetMenuLink($instance, $old_weight);

    // Tests the menus are listed alphabetically
    // Delete all existing menus.
    $existing = Menu::loadMultiple();
    foreach ($existing as $existingMenu) {
      $existingMenu->delete();
    }

    // Test alphabetical order without pager.
    $menu_entities = [];
    for ($i = 1; $i < 6; $i++) {
      $menu = strtolower($this->getRandomGenerator()->name());
      $menu_entity = Menu::create(['id' => $menu, 'label' => $menu]);
      $menu_entities[] = $menu_entity;
      $menu_entity->save();
    }
    uasort($menu_entities, [Menu::class, 'sort']);
    $menu_entities = array_values($menu_entities);
    $this->drupalGet('/admin/structure/menu');
    $base_path = parse_url($this->baseUrl, PHP_URL_PATH) ?? '';
    $first_link = $this->assertSession()->elementExists('css', 'tbody tr:nth-of-type(1) a');
    $last_link = $this->assertSession()->elementExists('css', 'tbody tr:nth-of-type(5) a');
    $this->assertEquals($first_link->getAttribute('href'), sprintf('%s/admin/structure/menu/manage/%s', $base_path, $menu_entities[0]->label()));
    $this->assertEquals($last_link->getAttribute('href'), sprintf('%s/admin/structure/menu/manage/%s', $base_path, $menu_entities[4]->label()));

    // Test alphabetical order with pager.
    $new_menu_entities = [];
    for ($i = 1; $i < 61; $i++) {
      $new_menu = strtolower($this->getRandomGenerator()->name());
      $new_menu_entity = Menu::create(['id' => $new_menu, 'label' => $new_menu]);
      $new_menu_entities[] = $new_menu_entity;
      $new_menu_entity->save();
    }
    $menu_entities = array_merge($menu_entities, $new_menu_entities);

    // To accommodate the current non-natural sorting of the pager, we have to
    // first non-natural sort the array of menu entities, and then do a
    // natural-sort on the ones that are on page 1.
    sort($menu_entities);
    $menu_entities_page_one = array_slice($menu_entities, 50, 64, TRUE);
    uasort($menu_entities_page_one, [Menu::class, 'sort']);
    $menu_entities_page_one = array_values($menu_entities_page_one);

    $this->drupalGet('/admin/structure/menu', [
      'query' => [
        'page' => 1,
      ],
    ]);
    $first_link = $this->assertSession()->elementExists('css', 'tbody tr:nth-of-type(1) a');
    $last_link = $this->assertSession()->elementExists('css', 'tbody tr:nth-of-type(15) a');
    $this->assertEquals($first_link->getAttribute('href'), sprintf('%s/admin/structure/menu/manage/%s', $base_path, $menu_entities_page_one[0]->label()));
    $this->assertEquals($last_link->getAttribute('href'), sprintf('%s/admin/structure/menu/manage/%s', $base_path, $menu_entities_page_one[14]->label()));
  }

  /**
   * Adds a custom menu using CRUD functions.
   */
  public function addCustomMenuCRUD(): void {
    // Add a new custom menu.
    $menu_name = $this->randomMachineName(MenuStorage::MAX_ID_LENGTH);
    $label = $this->randomMachineName(16);

    $menu = Menu::create([
      'id' => $menu_name,
      'label' => $label,
      'description' => 'Description text',
    ]);
    $menu->save();

    // Assert the new menu.
    $this->drupalGet('admin/structure/menu/manage/' . $menu_name);
    $this->assertSession()->pageTextContains($label);

    // Edit the menu.
    $new_label = $this->randomMachineName(16);
    $menu->set('label', $new_label);
    $menu->save();
    $this->drupalGet('admin/structure/menu/manage/' . $menu_name);
    $this->assertSession()->pageTextContains($new_label);

    // Delete the custom menu via the UI to testing destination handling.
    $this->drupalGet('admin/structure/menu');
    $this->assertSession()->pageTextContains($new_label);
    // Click the "Delete menu" operation in the Tools row.
    $links = $this->xpath('//*/td[contains(text(),:menu_label)]/following::a[normalize-space()=:link_label]', [':menu_label' => $new_label, ':link_label' => 'Delete menu']);
    $links[0]->click();
    $this->submitForm([], 'Delete');
    $this->assertSession()->addressEquals('admin/structure/menu');
    $this->assertSession()->responseContains("The menu <em class=\"placeholder\">$new_label</em> has been deleted.");
  }

  /**
   * Creates a custom menu.
   *
   * @return \Drupal\system\Entity\Menu
   *   The custom menu that has been created.
   */
  public function addCustomMenu() {
    // Try adding a menu using a menu_name that is too long.
    $this->drupalGet('admin/structure/menu/add');
    $menu_name = $this->randomMachineName(MenuStorage::MAX_ID_LENGTH + 1);
    $label = $this->randomMachineName(16);
    $edit = [
      'id' => $menu_name,
      'description' => '',
      'label' => $label,
    ];
    $this->drupalGet('admin/structure/menu/add');
    $this->submitForm($edit, 'Save');

    // Verify that using a menu_name that is too long results in a validation
    // message.
    $this->assertSession()->pageTextContains("Menu name cannot be longer than " . MenuStorage::MAX_ID_LENGTH . " characters but is currently " . mb_strlen($menu_name) . " characters long.");

    // Change the menu_name so it no longer exceeds the maximum length.
    $menu_name = $this->randomMachineName(MenuStorage::MAX_ID_LENGTH);
    $edit['id'] = $menu_name;
    $this->drupalGet('admin/structure/menu/add');
    $this->submitForm($edit, 'Save');

    // Verify that no validation error is given for menu_name length.
    $this->assertSession()->pageTextNotContains("Menu name cannot be longer than " . MenuStorage::MAX_ID_LENGTH . " characters but is currently " . mb_strlen($menu_name) . " characters long.");

    // Verify that the confirmation message is displayed.
    $this->assertSession()->pageTextContains("Menu $label has been added.");
    $this->drupalGet('admin/structure/menu');
    $this->assertSession()->pageTextContains($label);

    // Confirm that the custom menu block is available.
    $this->drupalGet('admin/structure/block/list/' . $this->config('system.theme')->get('default'));
    $this->clickLink('Place block');
    $this->assertSession()->pageTextContains($label);

    // Enable the block.
    $block = $this->drupalPlaceBlock('system_menu_block:' . $menu_name, [
      'level' => 1,
      'depth' => NULL,
      'expand_all_items' => FALSE,
    ]);
    $this->blockPlacements[$menu_name] = $block->id();
    return Menu::load($menu_name);
  }

  /**
   * Deletes the locally stored custom menu.
   *
   * This deletes the custom menu that is stored in $this->menu and performs
   * tests on the menu delete user interface.
   */
  public function deleteCustomMenu(): void {
    $menu_name = $this->menu->id();
    $label = $this->menu->label();

    // Delete custom menu.
    $this->drupalGet("admin/structure/menu/manage/{$menu_name}/delete");
    $this->submitForm([], 'Delete');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals("admin/structure/menu");
    $this->assertSession()->pageTextContains("The menu $label has been deleted.");
    $this->assertNull(Menu::load($menu_name), 'Custom menu was deleted');
    // Test if all menu links associated with the menu were removed from
    // database.
    $result = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties(['menu_name' => $menu_name]);
    $this->assertEmpty($result, 'All menu links associated with the custom menu were deleted.');

    // Make sure there's no delete button on system menus.
    $this->drupalGet('admin/structure/menu/manage/main');
    $this->assertSession()->responseNotContains('edit-delete');

    // Try to delete the main menu.
    $this->drupalGet('admin/structure/menu/manage/main/delete');
    $this->assertSession()->pageTextContains('You are not authorized to access this page.');
  }

  /**
   * Tests menu functionality.
   */
  public function doMenuTests(): void {
    // Add a link to the tools menu first, to test cacheability metadata of the
    // destination query string.
    $this->drupalGet('admin/structure/menu/manage/tools');
    $this->clickLink('Add link');
    $link_title = $this->randomMachineName();
    $this->submitForm(['link[0][uri]' => '/', 'title[0][value]' => $link_title], 'Save');
    $this->assertSession()->linkExists($link_title);
    $this->assertSession()->addressEquals('admin/structure/menu/manage/tools');

    // Test adding a menu link direct from the menus listing page.
    $this->drupalGet('admin/structure/menu');
    // Click the "Add link" operation in the Tools row.
    $links = $this->xpath('//*/td[contains(text(),:menu_label)]/following::a[normalize-space()=:link_label]', [':menu_label' => 'Tools', ':link_label' => 'Add link']);
    $links[0]->click();
    $this->assertMatchesRegularExpression('#admin/structure/menu/manage/tools/add\?destination=(/[^/]*)*/admin/structure/menu/manage/tools$#', $this->getSession()->getCurrentUrl());
    $link_title = $this->randomMachineName();
    $this->submitForm(['link[0][uri]' => '/', 'title[0][value]' => $link_title], 'Save');
    $this->assertSession()->linkExists($link_title);
    $this->assertSession()->addressEquals('admin/structure/menu/manage/tools');

    $menu_name = $this->menu->id();

    // Access the menu via the overview form to ensure it does not add a
    // destination that breaks the user interface.
    $this->drupalGet('admin/structure/menu');

    // Select the edit menu link for our menu.
    $links = $this->xpath('//*/td[contains(text(),:menu_label)]/following::a[normalize-space()=:link_label]', [':menu_label' => (string) $this->menu->label(), ':link_label' => 'Edit menu']);
    $links[0]->click();

    // Test the 'Add link' local action.
    $this->clickLink('Add link');
    $link_title = $this->randomMachineName();
    $this->submitForm(['link[0][uri]' => '/', 'title[0][value]' => $link_title], 'Save');
    $this->assertSession()->addressEquals(Url::fromRoute('entity.menu.edit_form', ['menu' => $menu_name]));
    // Test the 'Edit' operation.
    $this->clickLink('Edit');
    $this->assertSession()->fieldValueEquals('title[0][value]', $link_title);
    $link_title = $this->randomMachineName();
    $this->submitForm(['title[0][value]' => $link_title], 'Save');
    $this->assertSession()->addressEquals(Url::fromRoute('entity.menu.edit_form', ['menu' => $menu_name]));
    // Test the 'Delete' operation.
    $this->clickLink('Delete');
    $this->assertSession()->pageTextContains("Are you sure you want to delete the custom menu link {$link_title}?");
    $this->submitForm([], 'Delete');
    $this->assertSession()->addressEquals(Url::fromRoute('entity.menu.edit_form', ['menu' => $menu_name]));

    // Clear the cache to ensure that recent caches aren't preventing us from
    // seeing a broken add link.
    $this->resetAll();
    $this->drupalGet('admin/structure/menu');

    // Select the edit menu link for our menu.
    $links = $this->xpath('//*/td[contains(text(),:menu_label)]/following::a[normalize-space()=:link_label]', [':menu_label' => (string) $this->menu->label(), ':link_label' => 'Edit menu']);
    $links[0]->click();

    // Test the 'Add link' local action.
    $this->clickLink('Add link');
    $link_title = $this->randomMachineName();
    $this->submitForm(['link[0][uri]' => '/', 'title[0][value]' => $link_title], 'Save');
    $this->assertSession()->linkExists($link_title);
    $this->assertSession()->addressEquals(Url::fromRoute('entity.menu.edit_form', ['menu' => $menu_name]));

    // Add nodes to use as links for menu links.
    $node1 = $this->drupalCreateNode(['type' => 'article']);
    $node2 = $this->drupalCreateNode(['type' => 'article']);
    $node3 = $this->drupalCreateNode(['type' => 'article']);
    $node4 = $this->drupalCreateNode(['type' => 'article']);
    // Create a node with an alias.
    $node5 = $this->drupalCreateNode([
      'type' => 'article',
      'path' => [
        'alias' => '/node5',
      ],
    ]);

    // Verify add link button.
    $this->drupalGet('admin/structure/menu');
    $this->assertSession()->linkByHrefExists('admin/structure/menu/manage/' . $menu_name . '/add', 0, "The add menu link button URL is correct");

    // Verify form defaults.
    $this->doMenuLinkFormDefaultsTest();

    // Add menu links.
    $item1 = $this->addMenuLink('', '/node/' . $node1->id(), $menu_name, TRUE);
    $item2 = $this->addMenuLink($item1->getPluginId(), '/node/' . $node2->id(), $menu_name, FALSE);
    $item3 = $this->addMenuLink($item2->getPluginId(), '/node/' . $node3->id(), $menu_name);

    // Hierarchy
    // <$menu_name>
    // - item1
    // -- item2
    // --- item3

    $this->assertMenuLink([
      'children' => [$item2->getPluginId(), $item3->getPluginId()],
      'parents' => [$item1->getPluginId()],
      // We assert the language code here to make sure that the language
      // selection element degrades gracefully without the Language module.
      'langcode' => 'en',
    ], $item1->getPluginId());
    $this->assertMenuLink([
      'children' => [$item3->getPluginId()],
      'parents' => [$item2->getPluginId(), $item1->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item2->getPluginId());
    $this->assertMenuLink([
      'children' => [],
      'parents' => [$item3->getPluginId(), $item2->getPluginId(), $item1->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item3->getPluginId());

    // Verify menu links.
    $this->verifyMenuLink($item1, $node1);
    $this->verifyMenuLink($item2, $node2, $item1, $node1);
    $this->verifyMenuLink($item3, $node3, $item2, $node2);

    // Add more menu links.
    $item4 = $this->addMenuLink('', '/node/' . $node4->id(), $menu_name);
    $item5 = $this->addMenuLink($item4->getPluginId(), '/node/' . $node5->id(), $menu_name);
    // Create a menu link pointing to an alias.
    $item6 = $this->addMenuLink($item4->getPluginId(), '/node5', $menu_name, TRUE, '0');

    // Hierarchy
    // <$menu_name>
    // - item1
    // -- item2
    // --- item3
    // - item4
    // -- item5
    // -- item6

    $this->assertMenuLink([
      'children' => [$item5->getPluginId(), $item6->getPluginId()],
      'parents' => [$item4->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item4->getPluginId());
    $this->assertMenuLink([
      'children' => [],
      'parents' => [$item5->getPluginId(), $item4->getPluginId()],
      'langcode' => 'en',
    ], $item5->getPluginId());
    $this->assertMenuLink([
      'children' => [],
      'parents' => [$item6->getPluginId(), $item4->getPluginId()],
      'route_name' => 'entity.node.canonical',
      'route_parameters' => ['node' => $node5->id()],
      'url' => '',
      // See above.
      'langcode' => 'en',
    ], $item6->getPluginId());

    // Modify menu links.
    $this->modifyMenuLink($item1);
    $this->modifyMenuLink($item2);

    // Toggle menu links.
    $this->toggleMenuLink($item1);
    $this->toggleMenuLink($item2);

    // Test the "Add child" link for two siblings.
    $this->verifyAddChildLink($item5);
    $this->verifyAddChildLink($item6);

    // Move link and verify that descendants are updated.
    $this->moveMenuLink($item2, $item5->getPluginId(), $menu_name);
    // Hierarchy
    // <$menu_name>
    // - item1
    // - item4
    // -- item5
    // --- item2
    // ---- item3
    // -- item6

    $this->assertMenuLink([
      'children' => [],
      'parents' => [$item1->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item1->getPluginId());
    $this->assertMenuLink([
      'children' => [$item5->getPluginId(), $item6->getPluginId(), $item2->getPluginId(), $item3->getPluginId()],
      'parents' => [$item4->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item4->getPluginId());

    $this->assertMenuLink([
      'children' => [$item2->getPluginId(), $item3->getPluginId()],
      'parents' => [$item5->getPluginId(), $item4->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item5->getPluginId());
    $this->assertMenuLink([
      'children' => [$item3->getPluginId()],
      'parents' => [$item2->getPluginId(), $item5->getPluginId(), $item4->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item2->getPluginId());
    $this->assertMenuLink([
      'children' => [],
      'parents' => [$item3->getPluginId(), $item2->getPluginId(), $item5->getPluginId(), $item4->getPluginId()],
      // See above.
      'langcode' => 'en',
    ], $item3->getPluginId());

    // Add 102 menu links with increasing weights, then make sure the last-added
    // item's weight doesn't get changed because of the old hardcoded delta=50.
    $items = [];
    for ($i = -50; $i <= 51; $i++) {
      $items[$i] = $this->addMenuLink('', '/node/' . $node1->id(), $menu_name, TRUE, strval($i));
    }
    $this->assertMenuLink(['weight' => '51'], $items[51]->getPluginId());

    // Disable a link and then re-enable the link via the overview form.
    $this->disableMenuLink($item1);
    $edit = [];
    $edit['links[menu_plugin_id:' . $item1->getPluginId() . '][enabled]'] = TRUE;
    $this->drupalGet('admin/structure/menu/manage/' . $item1->getMenuName());
    $this->submitForm($edit, 'Save');

    // Mark item2, item4 and item5 as expanded.
    // This is done in order to show them on the frontpage.
    $item2->expanded->value = 1;
    $item2->save();
    $item4->expanded->value = 1;
    $item4->save();
    $item5->expanded->value = 1;
    $item5->save();

    // Verify in the database.
    $this->assertMenuLink(['enabled' => 1], $item1->getPluginId());

    // Add an external link.
    $item7 = $this->addMenuLink('', 'https://www.drupal.org', $menu_name);
    $this->assertMenuLink(['url' => 'https://www.drupal.org'], $item7->getPluginId());

    // Add <front> menu item.
    $item8 = $this->addMenuLink('', '/', $menu_name);
    $this->assertMenuLink(['route_name' => '<front>'], $item8->getPluginId());
    $this->drupalGet('');
    $this->assertSession()->statusCodeEquals(200);
    // Make sure we get routed correctly.
    $this->clickLink($item8->getTitle());
    $this->assertSession()->statusCodeEquals(200);

    // Check invalid menu link parents.
    $this->checkInvalidParentMenuLinks();

    // Save menu links for later tests.
    $this->items[] = $item1;
    $this->items[] = $item2;
  }

  /**
   * Test logout link isn't displayed when the user is logged out.
   */
  public function testLogoutLinkVisibility(): void {
    $adminUserWithLinkAnyPage = $this->drupalCreateUser([
      'access administration pages',
      'administer blocks',
      'administer menu',
      'create article content',
      'link to any page',
    ]);
    $this->drupalLogin($adminUserWithLinkAnyPage);
    $this->addMenuLink('', '/user/logout', 'main');
    $assert = $this->assertSession();
    // Verify that any link with logout URL is displayed.
    $assert->linkByHrefExists('user/logout');

    // Verify that any link with logout URL is not displayed.
    $this->drupalLogout();
    $assert->linkByHrefNotExists('user/logout');
  }

  /**
   * Ensures that the proper default values are set when adding a menu link.
   */
  protected function doMenuLinkFormDefaultsTest(): void {
    $this->drupalGet("admin/structure/menu/manage/tools/add");
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->fieldValueEquals('title[0][value]', '');
    $this->assertSession()->fieldValueEquals('link[0][uri]', '');

    $this->assertSession()->checkboxNotChecked('edit-expanded-value');
    $this->assertSession()->checkboxChecked('edit-enabled-value');

    $this->assertSession()->fieldValueEquals('description[0][value]', '');
    $this->assertSession()->fieldValueEquals('weight[0][value]', 0);
  }

  /**
   * Adds and removes a menu link with a query string and fragment.
   */
  public function testMenuQueryAndFragment(): void {
    $this->drupalLogin($this->adminUser);

    // Make a path with query and fragment on.
    $path = '/test-page?arg1=value1&arg2=value2';
    $item = $this->addMenuLink('', $path);

    // Check that the path has both the query and fragment.
    $this->drupalGet('admin/structure/menu/item/' . $item->id() . '/edit');
    $this->assertSession()->fieldValueEquals('link[0][uri]', $path);

    // Now change the path to something without query and fragment.
    $path = '/test-page';
    $this->drupalGet('admin/structure/menu/item/' . $item->id() . '/edit');
    $this->submitForm(['link[0][uri]' => $path], 'Save');
    $this->drupalGet('admin/structure/menu/item/' . $item->id() . '/edit');
    $this->assertSession()->fieldValueEquals('link[0][uri]', $path);

    // Use <front>#fragment and ensure that saving it does not lose its content.
    $path = '<front>?arg1=value#fragment';
    $item = $this->addMenuLink('', $path);

    $this->drupalGet('admin/structure/menu/item/' . $item->id() . '/edit');
    $this->assertSession()->fieldValueEquals('link[0][uri]', $path);

    $this->drupalGet('admin/structure/menu/item/' . $item->id() . '/edit');
    $this->submitForm([], 'Save');

    $this->drupalGet('admin/structure/menu/item/' . $item->id() . '/edit');
    $this->assertSession()->fieldValueEquals('link[0][uri]', $path);
  }

  /**
   * Tests renaming the built-in menu.
   */
  public function testSystemMenuRename(): void {
    $this->drupalLogin($this->adminUser);
    $edit = [
      'label' => $this->randomMachineName(16),
    ];
    $this->drupalGet('admin/structure/menu/manage/main');
    $this->submitForm($edit, 'Save');

    // Make sure menu shows up with new name in block addition.
    $default_theme = $this->config('system.theme')->get('default');
    $this->drupalGet('admin/structure/block/list/' . $default_theme);
    $this->clickLink('Place block');
    $this->assertSession()->pageTextContains($edit['label']);
  }

  /**
   * Tests that menu items pointing to unpublished nodes are editable.
   */
  public function testUnpublishedNodeMenuItem(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access administration pages',
      'administer blocks',
      'administer menu',
      'create article content',
      'bypass node access',
    ]));
    // Create an unpublished node.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);

    $item = $this->addMenuLink('', '/node/' . $node->id());
    $this->modifyMenuLink($item);

    // Test that a user with 'administer menu' but without 'bypass node access'
    // cannot see the menu item.
    $this->drupalLogout();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/structure/menu/manage/' . $item->getMenuName());
    $this->assertSession()->linkExists($item->getTitle());
    // The cache contexts associated with the (in)accessible menu links are
    // bubbled. See DefaultMenuLinkTreeManipulators::menuLinkCheckAccess().
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Contexts', 'user.permissions');

    // The menu link admin is able to administer the link but cannot access the
    // route as is not granted with 'bypass node access' permission.
    $this->clickLink($item->getTitle());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Adds a menu link using the UI.
   *
   * @param string $parent
   *   Optional parent menu link id.
   * @param string $path
   *   The path to enter on the form. Defaults to the front page.
   * @param string $menu_name
   *   Menu name. Defaults to 'tools'.
   * @param bool $expanded
   *   Whether or not this menu link is expanded. Setting this to TRUE should
   *   test whether it works when we do the authenticatedUser tests. Defaults
   *   to FALSE.
   * @param string $weight
   *   Menu weight. Defaults to 0.
   *
   * @return \Drupal\menu_link_content\Entity\MenuLinkContent
   *   A menu link entity.
   */
  public function addMenuLink($parent = '', $path = '/', $menu_name = 'tools', $expanded = FALSE, $weight = '0') {
    // View add menu link page.
    $this->drupalGet("admin/structure/menu/manage/$menu_name/add");
    $this->assertSession()->statusCodeEquals(200);

    $title = '!link_' . $this->randomMachineName(16);
    $edit = [
      'link[0][uri]' => $path,
      'title[0][value]' => $title,
      'description[0][value]' => '',
      'enabled[value]' => 1,
      'expanded[value]' => $expanded,
      'menu_parent' => $menu_name . ':' . $parent,
      'weight[0][value]' => $weight,
    ];

    // Add menu link.
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The menu link has been saved.');

    $menu_links = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties(['title' => $title]);

    $menu_link = reset($menu_links);
    $this->assertInstanceOf(MenuLinkContent::class, $menu_link);
    $this->assertMenuLink(['menu_name' => $menu_name, 'children' => [], 'parent' => $parent], $menu_link->getPluginId());

    return $menu_link;
  }

  /**
   * Attempts to add menu link with invalid path or no access permission.
   */
  public function addInvalidMenuLink(): void {
    foreach (['access' => '/admin/people/permissions'] as $type => $link_path) {
      $edit = [
        'link[0][uri]' => $link_path,
        'title[0][value]' => 'title',
      ];
      $this->drupalGet("admin/structure/menu/manage/{$this->menu->id()}/add");
      $this->submitForm($edit, 'Save');
      $this->assertSession()->pageTextContains("The path '{$link_path}' is inaccessible.");
    }
  }

  /**
   * Tests that parent options are limited by depth when adding menu links.
   */
  public function checkInvalidParentMenuLinks(): void {
    $last_link = NULL;
    $plugin_ids = [];

    // Get the max depth of the tree.
    $menu_link_tree = \Drupal::service('menu.link_tree');
    $max_depth = $menu_link_tree->maxDepth();

    // Create a maximum number of menu links, each a child of the previous.
    for ($i = 0; $i <= $max_depth - 1; $i++) {
      $parent = $last_link ? 'tools:' . $last_link->getPluginId() : 'tools:';
      $title = 'title' . $i;
      $edit = [
        'link[0][uri]' => '/',
        'title[0][value]' => $title,
        'menu_parent' => $parent,
        'description[0][value]' => '',
        'enabled[value]' => 1,
        'expanded[value]' => FALSE,
        'weight[0][value]' => '0',
      ];
      $this->drupalGet("admin/structure/menu/manage/tools/add");
      $this->submitForm($edit, 'Save');
      $menu_links = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties(['title' => $title]);
      $last_link = reset($menu_links);
      $plugin_ids[] = $last_link->getPluginId();
    }

    $last_plugin_id = array_pop($plugin_ids);

    // The last link cannot be a parent in the new menu link form.
    $this->drupalGet('admin/structure/menu/manage/tools/add');
    $value = 'tools:' . $last_plugin_id;
    $this->assertSession()->optionNotExists('edit-menu-parent', $value);

    // All but the last link can be parents in the new menu link form.
    foreach ($plugin_ids as $plugin_id) {
      $this->assertSession()->optionExists('edit-menu-parent', 'tools:' . $plugin_id);
    }

    // The last link does not have an "Add child" operation.
    $this->drupalGet('admin/structure/menu/manage/tools');
    $this->assertSession()->linkByHrefNotExists('parent=' . urlencode($last_plugin_id));

    // All but the last link do have an "Add child" operation.
    foreach ($plugin_ids as $plugin_id) {
      $this->assertSession()->linkByHrefExists('parent=' . urlencode($plugin_id));
    }
  }

  /**
   * Verifies a menu link using the UI.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   Menu link.
   * @param object $item_node
   *   Menu link content node.
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $parent
   *   Parent menu link.
   * @param object $parent_node
   *   Parent menu link content node.
   */
  public function verifyMenuLink(MenuLinkContent $item, $item_node, ?MenuLinkContent $parent = NULL, $parent_node = NULL): void {
    // View home page.
    $this->drupalGet('');
    $this->assertSession()->statusCodeEquals(200);

    // Verify parent menu link.
    if (isset($parent)) {
      // Verify menu link.
      $title = $parent->getTitle();
      $this->assertSession()->linkExists($title, 0, 'Parent menu link was displayed');

      // Verify menu link.
      $this->clickLink($title);
      $title = $parent_node->label();
      $this->assertSession()->titleEquals("$title | Drupal");
    }

    // Verify menu link.
    $title = $item->getTitle();
    $this->assertSession()->linkExists($title, 0, 'Menu link was displayed');

    // Verify menu link.
    $this->clickLink($title);
    $title = $item_node->label();
    $this->assertSession()->titleEquals("$title | Drupal");
  }

  /**
   * Changes the parent of a menu link using the UI.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   The menu link item to move.
   * @param int $parent
   *   The id of the new parent.
   * @param string $menu_name
   *   The menu the menu link will be moved to.
   */
  public function moveMenuLink(MenuLinkContent $item, $parent, $menu_name): void {
    $menu_link_id = $item->id();

    $edit = [
      'menu_parent' => $menu_name . ':' . $parent,
    ];
    $this->drupalGet("admin/structure/menu/item/{$menu_link_id}/edit");
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Modifies a menu link using the UI.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   Menu link entity.
   */
  public function modifyMenuLink(MenuLinkContent $item): void {
    $item->title->value = $this->randomMachineName(16);

    $menu_link_id = $item->id();
    $title = $item->getTitle();

    // Edit menu link.
    $edit = [];
    $edit['title[0][value]'] = $title;
    $this->drupalGet("admin/structure/menu/item/{$menu_link_id}/edit");
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The menu link has been saved.');
    // Verify menu link.
    $this->drupalGet('admin/structure/menu/manage/' . $item->getMenuName());
    $this->assertSession()->pageTextContains($title);
  }

  /**
   * Verifies that the "Add child" link selects the correct parent item.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   Menu link entity.
   */
  public function verifyAddChildLink(MenuLinkContent $item): void {
    $menu_name = $item->getMenuName();

    $this->drupalGet('admin/structure/menu/manage/' . $menu_name);
    $links = $this->xpath('//a[normalize-space()=:item_label]/following::a[normalize-space()=:link_label]', [
      ':item_label' => $item->getTitle(),
      ':link_label' => 'Add child',
    ]);
    $links[0]->click();
    $option = $this->assertSession()->optionExists('edit-menu-parent', $menu_name . ':' . $item->getPluginId());
    $this->assertTrue($option->isSelected());
  }

  /**
   * Resets a standard menu link using the UI.
   *
   * @param \Drupal\Core\Menu\MenuLinkInterface $menu_link
   *   The Menu link.
   * @param int $old_weight
   *   Original title for menu link.
   */
  public function resetMenuLink(MenuLinkInterface $menu_link, $old_weight): void {
    // Reset menu link.
    $this->drupalGet("admin/structure/menu/link/{$menu_link->getPluginId()}/reset");
    $this->submitForm([], 'Reset');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The menu link was reset to its default settings.');

    // Verify menu link.
    $instance = \Drupal::service('plugin.manager.menu.link')->createInstance($menu_link->getPluginId());
    $this->assertEquals($old_weight, $instance->getWeight(), 'Resets to the old weight.');
  }

  /**
   * Deletes a menu link using the UI.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   Menu link.
   */
  public function deleteMenuLink(MenuLinkContent $item): void {
    $menu_link_id = $item->id();
    $title = $item->getTitle();

    // Delete menu link.
    $this->drupalGet("admin/structure/menu/item/{$menu_link_id}/delete");
    $this->submitForm([], 'Delete');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains("The menu link $title has been deleted.");

    // Verify deletion.
    $this->drupalGet('');
    $this->assertSession()->pageTextNotContains($title);
  }

  /**
   * Alternately disables and enables a menu link.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   Menu link.
   */
  public function toggleMenuLink(MenuLinkContent $item): void {
    $this->disableMenuLink($item);

    // Verify menu link is absent.
    $this->drupalGet('');
    $this->assertSession()->pageTextNotContains($item->getTitle());
    $this->enableMenuLink($item);

    // Verify menu link is displayed.
    $this->drupalGet('');
    $this->assertSession()->pageTextContains($item->getTitle());
  }

  /**
   * Disables a menu link.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   Menu link.
   */
  public function disableMenuLink(MenuLinkContent $item): void {
    $menu_link_id = $item->id();
    $edit['enabled[value]'] = FALSE;
    $this->drupalGet("admin/structure/menu/item/{$menu_link_id}/edit");
    $this->submitForm($edit, 'Save');

    // Unlike most other modules, there is no confirmation message displayed.
    // Verify in the database.
    $this->assertMenuLink(['enabled' => 0], $item->getPluginId());
  }

  /**
   * Enables a menu link.
   *
   * @param \Drupal\menu_link_content\Entity\MenuLinkContent $item
   *   Menu link.
   */
  public function enableMenuLink(MenuLinkContent $item): void {
    $menu_link_id = $item->id();
    $edit['enabled[value]'] = TRUE;
    $this->drupalGet("admin/structure/menu/item/{$menu_link_id}/edit");
    $this->submitForm($edit, 'Save');

    // Verify in the database.
    $this->assertMenuLink(['enabled' => 1], $item->getPluginId());
  }

  /**
   * Tests if admin users, other than UID1, can access parents AJAX callback.
   */
  public function testMenuParentsJsAccess(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer menu']));
    // Just check access to the callback overall, the POST data is irrelevant.
    $this->drupalGet('admin/structure/menu/parents', ['query' => [MainContentViewSubscriber::WRAPPER_FORMAT => 'drupal_ajax']], ['X-Requested-With' => 'XMLHttpRequest']);
    $this->assertSession()->statusCodeEquals(200);

    // Log in as authenticated user.
    $this->drupalLogin($this->drupalCreateUser());
    // Check that a simple user is not able to access the menu.
    $this->drupalGet('admin/structure/menu/parents', ['query' => [MainContentViewSubscriber::WRAPPER_FORMAT => 'drupal_ajax']], ['X-Requested-With' => 'XMLHttpRequest']);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the "expand all items" feature.
   */
  public function testExpandAllItems(): void {
    $this->drupalLogin($this->adminUser);
    $menu = $this->addCustomMenu();
    $node = $this->drupalCreateNode(['type' => 'article']);

    // Create three menu items, none of which are expanded.
    $parent = $this->addMenuLink('', $node->toUrl()->toString(), $menu->id(), FALSE);
    $child_1 = $this->addMenuLink($parent->getPluginId(), $node->toUrl()->toString(), $menu->id(), FALSE);
    $child_2 = $this->addMenuLink($parent->getPluginId(), $node->toUrl()->toString(), $menu->id(), FALSE);

    // The menu will not automatically show all levels of depth.
    $this->drupalGet('<front>');
    $this->assertSession()->linkExists($parent->getTitle());
    $this->assertSession()->linkNotExists($child_1->getTitle());
    $this->assertSession()->linkNotExists($child_2->getTitle());

    // Update the menu block to show all levels of depth as expanded.
    $block_id = $this->blockPlacements[$menu->id()];
    $this->drupalGet('admin/structure/block/manage/' . $block_id);
    $this->assertSession()->checkboxNotChecked('settings[expand_all_items]');
    $this->submitForm([
      'settings[depth]' => 2,
      'settings[level]' => 1,
      'settings[expand_all_items]' => 1,
    ], 'Save block');

    // Ensure the setting is persisted.
    $this->drupalGet('admin/structure/block/manage/' . $block_id);
    $this->assertSession()->checkboxChecked('settings[expand_all_items]');

    // Ensure all three links are shown, including the children which would
    // usually be hidden without the "expand all items" setting.
    $this->drupalGet('<front>');
    $this->assertSession()->linkExists($parent->getTitle());
    $this->assertSession()->linkExists($child_1->getTitle());
    $this->assertSession()->linkExists($child_2->getTitle());
  }

  /**
   * Returns standard menu link.
   *
   * @return \Drupal\Core\Menu\MenuLinkInterface
   *   A menu link plugin.
   */
  private function getStandardMenuLink() {
    // Retrieve menu link id of the Log out menu link, which will always be on
    // the front page.
    /** @var \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager */
    $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
    $instance = $menu_link_manager->getInstance(['id' => 'user.logout']);

    $this->assertTrue((bool) $instance, 'Standard menu link was loaded');
    return $instance;
  }

  /**
   * Verifies the logged in user has the desired access to various menu pages.
   *
   * @param int $response
   *   (optional) The expected HTTP response code. Defaults to 200.
   */
  private function verifyAccess($response = 200): void {
    // View menu help page.
    $this->drupalGet('admin/help/menu');
    $this->assertSession()->statusCodeEquals($response);
    if ($response == 200) {
      $this->assertSession()->pageTextContains('Menu');
    }

    // View menu build overview page.
    $this->drupalGet('admin/structure/menu');
    $this->assertSession()->statusCodeEquals($response);
    if ($response == 200) {
      $this->assertSession()->pageTextContains('Menus');
    }

    // View tools menu customization page.
    $this->drupalGet('admin/structure/menu/manage/' . $this->menu->id());
    $this->assertSession()->statusCodeEquals($response);
    if ($response == 200) {
      $this->assertSession()->pageTextContains('Tools');
    }

    // View menu edit page for a static link.
    $item = $this->getStandardMenuLink();
    $this->drupalGet('admin/structure/menu/link/' . $item->getPluginId() . '/edit');
    $this->assertSession()->statusCodeEquals($response);
    if ($response == 200) {
      $this->assertSession()->pageTextContains('Edit menu item');
    }

    // View add menu page.
    $this->drupalGet('admin/structure/menu/add');
    $this->assertSession()->statusCodeEquals($response);
    if ($response == 200) {
      $this->assertSession()->pageTextContains('Menus');
    }
  }

  /**
   * Tests menu block settings.
   */
  protected function doTestMenuBlock(): void {
    $menu_id = $this->menu->id();
    $block_id = $this->blockPlacements[$menu_id];
    $this->drupalGet('admin/structure/block/manage/' . $block_id);
    $this->submitForm([
      'settings[depth]' => 3,
      'settings[level]' => 2,
    ], 'Save block');
    $block = Block::load($block_id);
    $settings = $block->getPlugin()->getConfiguration();
    $this->assertEquals(3, $settings['depth']);
    $this->assertEquals(2, $settings['level']);
    // Reset settings.
    $block->getPlugin()->setConfigurationValue('depth', NULL);
    $block->getPlugin()->setConfigurationValue('level', 1);
    $block->save();
  }

  /**
   * Tests that menu links with pending revisions can not be re-parented.
   */
  public function testMenuUiWithPendingRevisions(): void {
    $this->drupalLogin($this->adminUser);
    $assert_session = $this->assertSession();

    // Add four menu links in two separate menus.
    $menu_1 = $this->addCustomMenu();
    $root_1 = $this->addMenuLink('', '/', $menu_1->id());
    $this->addMenuLink($root_1->getPluginId(), '/', $menu_1->id());

    $menu_2 = $this->addCustomMenu();
    $root_2 = $this->addMenuLink('', '/', $menu_2->id());
    $child_2 = $this->addMenuLink($root_2->getPluginId(), '/', $menu_2->id());

    $this->drupalGet('admin/structure/menu/manage/' . $menu_2->id());
    $assert_session->pageTextNotContains($menu_2->label() . ' contains 1 menu link with pending revisions. Manipulation of a menu tree having links with pending revisions is not supported, but you can re-enable manipulation by getting each menu link to a published state.');

    $this->drupalGet('admin/structure/menu/manage/' . $menu_1->id());
    $assert_session->pageTextNotContains($menu_1->label() . ' contains 1 menu link with pending revisions. Manipulation of a menu tree having links with pending revisions is not supported, but you can re-enable manipulation by getting each menu link to a published state.');

    // Create a pending revision for one of the menu links and check that it can
    // no longer be re-parented in the UI. We can not create pending revisions
    // through the UI yet so we have to use API calls.
    \Drupal::entityTypeManager()->getStorage('menu_link_content')->createRevision($child_2, FALSE)->save();

    $this->drupalGet('admin/structure/menu/manage/' . $menu_2->id());
    $assert_session->pageTextContains($menu_2->label() . ' contains 1 menu link with pending revisions. Manipulation of a menu tree having links with pending revisions is not supported, but you can re-enable manipulation by getting each menu link to a published state.');

    // Check that the 'Enabled' checkbox is hidden for a pending revision.
    $this->assertNotEmpty($this->cssSelect('input[name="links[menu_plugin_id:' . $root_2->getPluginId() . '][enabled]"]'), 'The publishing status of a default revision can be changed.');
    $this->assertEmpty($this->cssSelect('input[name="links[menu_plugin_id:' . $child_2->getPluginId() . '][enabled]"]'), 'The publishing status of a pending revision can not be changed.');

    $this->drupalGet('admin/structure/menu/manage/' . $menu_1->id());
    $assert_session->pageTextNotContains($menu_1->label() . ' contains 1 menu link with pending revisions. Manipulation of a menu tree having links with pending revisions is not supported, but you can re-enable manipulation by getting each menu link to a published state.');

    // Check that the menu overview form can be saved without errors when there
    // are pending revisions.
    $this->drupalGet('admin/structure/menu/manage/' . $menu_2->id());
    $this->submitForm([], 'Save');
    $this->assertSession()->elementNotExists('xpath', '//div[contains(@class, "messages--error")]');
  }

  /**
   * Tests the user login/logout links.
   */
  public function testUserLoginUserLogoutLinks(): void {
    MenuLinkContent::create([
      'menu' => 'tools',
      'link' => [
        'uri' => 'internal:/user/login',
      ],
      'title' => 'Login',
    ])->save();
    MenuLinkContent::create([
      'menu' => 'tools',
      'link' => [
        'uri' => 'internal:/user/logout',
      ],
      'title' => 'Logout',
    ])->save();

    $assert = $this->assertSession();

    $block = $this->drupalPlaceBlock('system_menu_block:tools');
    $this->drupalGet('<front>');
    $assert->linkExists('Login');
    $assert->linkNotExists('Logout');

    $this->drupalLogin($this->createUser());
    $this->drupalGet('<front>');
    $assert->linkNotExists('Login');
    $assert->linkExists('Logout');

    // Delete the block, we're now checking the Menu UI form.
    $block->delete();

    $this->drupalLogin($this->createUser(['administer menu']));
    $this->drupalGet('admin/structure/menu/manage/tools');

    $assert->linkExists('Logout');
    // Check that the login link is accessible even the route is not.
    $this->assertFalse(Url::fromRoute('user.login')->access($this->loggedInUser));
    $assert->linkExists('Login');
  }

}
