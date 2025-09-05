<?php

namespace Drupal\ai_search_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides AI response + native Views results.
 *
 * @Block(
 *   id = "ai_search_block_response",
 *   admin_label = @Translation("AI Search Response"),
 *   category = @Translation("AI")
 * )
 */
class SearchResponseBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = new static($configuration, $plugin_id, $plugin_definition);
    $plugin->entityTypeManager = $container->get('entity_type.manager');
    return $plugin;
  }

  public function defaultConfiguration(): array {
    return [
      // Remove the database settings from here - they should come from the form block
    ];
  }

  public function build() {
    $build = [];
    $build['response_wrapper'] = [
      '#theme' => 'ai_search_block_response',
      '#output' => ' ',  // Empty space to ensure div renders
      '#rendered_form' => NULL,  // Add this if your template expects it
      '#suffix_text' => '',  // Add this if your template expects it
      '#weight' => 0,
    ];


    // Instead of using this block's configuration, find the form block's settings
    $blocks = $this->entityTypeManager->getStorage('block')->loadByProperties([
      'plugin' => 'ai_search_block',
      'theme' => $this->getTheme(),
    ]);

    $form_block = reset($blocks); // Get the first matching form block
    if ($form_block) {
      $settings = $form_block->get('settings');

      // Use the form block's database settings
      if (!empty($settings['enable_database_results']) && !empty($settings['database_results_view'])) {
        [$view_id, $display_id] = explode(':', $settings['database_results_view']);
        $plugin_id = "views_block:{$view_id}-{$display_id}";

        $plugin_block = \Drupal::service('plugin.manager.block')->createInstance($plugin_id, []);
        $views_build = $plugin_block->build();
        $views_build['#attributes']['id'] = 'ai-db-results-block';
        $build['db_results'] = $views_build + ['#weight' => 100];
      }
    }

    return $build;
  }

  /**
   * Gets the current theme name.
   *
   * @return string
   *   The current theme name.
   */
  protected function getTheme() {
    return \Drupal::service('theme.manager')->getActiveTheme()->getName();
  }
}
